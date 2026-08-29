/**
 * r2-media-worker: the only thing allowed to read the private R2 bucket
 * that backs this project's R2/direct-MP4 video source. Deployed behind
 * the `media.nangcuctvc.com` custom domain, replacing what used to be a
 * permanently public bucket.
 *
 * WordPress (`Tube_Core\Video\R2\R2PlaybackUrlSigner`) signs a temporary
 * URL — `https://media.nangcuctvc.com/<encoded-object-key>?exp=<unix
 * ts>&sig=<hex hmac>` — for one object key, valid for (by default) 10
 * minutes. This Worker is the other half of that contract: reconstruct
 * the exact same `<object-key>\n<exp>` message from the incoming
 * request, verify it with the same shared secret, reject anything
 * expired/tampered/missing/malformed with 403, and otherwise stream the
 * R2 object straight through — including real HTTP Range support for
 * seeking — without ever buffering the whole file in Worker memory.
 *
 * See this directory's README.md for required bindings/secrets and the
 * exact Cloudflare dashboard steps to deploy this.
 */

/**
 * Named exports below (alongside the default `fetch` export the Workers
 * runtime actually invokes) exist purely so `test/index.test.mjs` can
 * exercise this module's cryptographic/parsing logic directly with
 * plain Node, with no Workers runtime, R2 binding, or network access
 * needed -- exactly the "test everything possible locally" this
 * project's signed-URL work requires.
 */

/** Matches R2MediaUrlNormalizer::normalize()'s own traversal/control-character rejection. */
export function isValidObjectKey(key) {
    if ('' === key) {
        return false;
    }

    if (key.includes('..')) {
        return false;
    }

    // eslint-disable-next-line no-control-regex
    return !/[\x00-\x1f]/.test(key);
}

/**
 * Reconstructs the canonical, fully-decoded object key from the
 * request's URL path — the exact inverse of
 * R2MediaUrlNormalizer::public_url()'s per-segment rawurlencode(). `/`
 * is never itself percent-encoded by that method, so decoding the whole
 * path in one pass (rather than segment-by-segment) produces an
 * identical result and is simpler.
 */
export function decodeObjectKeyFromPath(pathname) {
    const encoded = pathname.replace(/^\/+/, '');

    try {
        return decodeURIComponent(encoded);
    } catch {
        return null;
    }
}

/** Constant-time-by-construction HMAC-SHA256 verification via Web Crypto. */
export async function verifySignature(secret, objectKey, expiresAt, signatureHex) {
    if (!/^[0-9a-f]+$/i.test(signatureHex) || signatureHex.length % 2 !== 0) {
        return false;
    }

    const signatureBytes = new Uint8Array(signatureHex.length / 2);

    for (let i = 0; i < signatureBytes.length; i++) {
        signatureBytes[i] = parseInt(signatureHex.substr(i * 2, 2), 16);
    }

    const key = await crypto.subtle.importKey(
        'raw',
        new TextEncoder().encode(secret),
        { name: 'HMAC', hash: 'SHA-256' },
        false,
        ['verify']
    );

    const message = new TextEncoder().encode(`${objectKey}\n${expiresAt}`);

    return crypto.subtle.verify('HMAC', key, signatureBytes, message);
}

/**
 * Parses a single-range `Range: bytes=...` header against a known total
 * size. Returns `null` for no/absent Range (serve the whole object), or
 * `'unsatisfiable'` for a range this size can't satisfy (caller sends
 * 416), or `{ offset, length }` for a valid single range.
 *
 * Deliberately rejects multi-range requests (a `,` in the header) by
 * falling back to "no range" (serve the whole object) rather than
 * attempting `multipart/byteranges` — real browsers/video elements only
 * ever send a single range when seeking an MP4, so supporting more would
 * be unused complexity and a wider attack surface for no real benefit.
 */
export function parseRange(rangeHeader, totalSize) {
    if (!rangeHeader || rangeHeader.includes(',')) {
        return null;
    }

    const match = /^bytes=(\d*)-(\d*)$/.exec(rangeHeader.trim());

    if (!match || ('' === match[1] && '' === match[2])) {
        return null;
    }

    let start;
    let end;

    if ('' === match[1]) {
        // Suffix range: bytes=-500 -> the last 500 bytes.
        const suffixLength = parseInt(match[2], 10);
        start = Math.max(0, totalSize - suffixLength);
        end = totalSize - 1;
    } else {
        start = parseInt(match[1], 10);
        end = '' === match[2] ? totalSize - 1 : parseInt(match[2], 10);
    }

    if (start > end || start >= totalSize) {
        return 'unsatisfiable';
    }

    end = Math.min(end, totalSize - 1);

    return { offset: start, length: end - start + 1, end };
}

/** Every rejection path funnels through here: no caching, no leaked detail beyond a generic message. */
function denied(status, message) {
    return new Response(message, {
        status,
        headers: { 'Cache-Control': 'private, no-store', 'Content-Type': 'text/plain; charset=utf-8' },
    });
}

export default {
    async fetch(request, env) {
        if ('GET' !== request.method && 'HEAD' !== request.method) {
            return denied(405, 'Method not allowed');
        }

        if (!env.R2_SIGNING_SECRET) {
            // Fails closed, matching R2PlaybackUrlSigner's own posture for
            // an unconfigured secret -- this Worker must never serve
            // unsigned/unverifiable requests just because deployment is
            // incomplete.
            return denied(500, 'Worker is not configured');
        }

        const url = new URL(request.url);
        const objectKey = decodeObjectKeyFromPath(url.pathname);

        if (null === objectKey || !isValidObjectKey(objectKey)) {
            return denied(403, 'Forbidden');
        }

        const expParam = url.searchParams.get('exp');
        const sigParam = url.searchParams.get('sig');

        if (!expParam || !sigParam || !/^\d+$/.test(expParam)) {
            return denied(403, 'Forbidden');
        }

        const expiresAt = parseInt(expParam, 10);

        if (Math.floor(Date.now() / 1000) > expiresAt) {
            return denied(403, 'Link expired');
        }

        const signatureValid = await verifySignature(env.R2_SIGNING_SECRET, objectKey, expiresAt, sigParam);

        if (!signatureValid) {
            return denied(403, 'Forbidden');
        }

        const head = await env.MEDIA_BUCKET.head(objectKey);

        if (null === head) {
            return denied(404, 'Not found');
        }

        const contentType = head.httpMetadata?.contentType || 'video/mp4';
        const totalSize = head.size;

        if ('HEAD' === request.method) {
            return new Response(null, {
                status: 200,
                headers: {
                    'Content-Type': contentType,
                    'Content-Length': String(totalSize),
                    'Accept-Ranges': 'bytes',
                    'Cache-Control': 'private, no-store',
                },
            });
        }

        const range = parseRange(request.headers.get('Range'), totalSize);

        if ('unsatisfiable' === range) {
            return new Response(null, {
                status: 416,
                headers: { 'Content-Range': `bytes */${totalSize}`, 'Cache-Control': 'private, no-store' },
            });
        }

        const object = await env.MEDIA_BUCKET.get(objectKey, range ? { range: { offset: range.offset, length: range.length } } : undefined);

        if (null === object) {
            return denied(404, 'Not found');
        }

        const headers = {
            'Content-Type': contentType,
            'Accept-Ranges': 'bytes',
            'Cache-Control': 'private, no-store',
        };

        if (range) {
            headers['Content-Range'] = `bytes ${range.offset}-${range.end}/${totalSize}`;
            headers['Content-Length'] = String(range.length);

            // object.body is a ReadableStream -- passed straight through as
            // the Response body, never read into memory first, so a large
            // MP4 streams through this Worker at whatever rate the client
            // consumes it rather than being buffered here.
            return new Response(object.body, { status: 206, headers });
        }

        headers['Content-Length'] = String(totalSize);

        return new Response(object.body, { status: 200, headers });
    },
};
