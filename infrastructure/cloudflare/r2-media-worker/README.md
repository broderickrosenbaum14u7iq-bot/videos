# r2-media-worker

Cloudflare Worker that makes the R2/direct-MP4 video source private. It
sits in front of the `media.nangcuctvc.com` custom domain (which used to
point straight at a **public** R2 bucket) and is now the *only* thing
that can read R2 objects: every request must carry a valid, unexpired
HMAC signature, or it gets `403`.

WordPress's `Tube_Core\Video\R2\R2PlaybackUrlSigner` generates the
signed URLs this Worker accepts. The two sides share one secret and one
message format — see that class's docblock for the PHP half of this
contract.

## How it works

```
https://media.nangcuctvc.com/<encoded-object-key>?exp=<unix-ts>&sig=<hex-hmac>
```

1. The Worker decodes the URL path back into the canonical object key
   (reversing WordPress's per-path-segment `rawurlencode()`).
2. It rebuilds the message `"<object-key>\n<exp>"` and verifies `sig`
   against it with the shared secret, using `crypto.subtle.verify()`
   (Web Crypto's HMAC verification — constant-time by construction, so
   there's no hand-rolled string comparison to get wrong).
3. Expired, missing, or invalid signatures get `403`. Path traversal
   (`..`) or control characters in the decoded key also get `403`,
   before the R2 bucket is ever touched.
4. A valid request streams straight from the `MEDIA_BUCKET` R2 binding —
   the object body is piped directly into the `Response`, never
   buffered in Worker memory, so this scales to large video files with
   flat memory usage regardless of file size.
5. `Range` requests (seeking) are translated into R2's native
   `{ offset, length }` range option and answered with a real `206
   Partial Content` + `Content-Range` + `Content-Length`. Only a single
   range is supported — real browsers/`<video>` elements never request
   more than one at a time, so `multipart/byteranges` was never needed.

## Why `Cache-Control: private, no-store`

Every response from this Worker — success or rejection — is
`private, no-store`. This deliberately opts the whole route **out** of
Cloudflare's edge cache. The alternative (caching a successfully
authorized response) creates exactly the risk this project's own
security requirements called out: a signed URL gets requested once,
Cloudflare caches that response at the edge, and the *cache key* (by
default, the URL including `?exp=&sig=`) would make each signed URL
cache independently — which sounds safe, but every subsequent request
*for that same still-cached URL* would be served straight from cache
with **no re-verification**, so a signature that naturally expired
could still serve stale cached bytes past its own expiry, and the only
way to close that gap is cache-busting logic this feature doesn't need.
At this project's traffic (well under what R2 + Workers costs describe
as meaningfully billable — see the cost estimate in the project's final
report), paying for a Worker invocation + R2 read on every single
request is the simplest design that has no cache/auth-bypass edge case
to reason about at all, and is the one implemented here.

## Required Cloudflare configuration

This repository contains only *source* — deploying it requires access
to the Cloudflare account that owns the `nangcuctvc.com` zone and the R2
bucket, which this automated environment does not have. Perform these
steps yourself:

1. **Make the R2 bucket private** (if it isn't already): in the
   Cloudflare dashboard → R2 → the bucket currently serving
   `media.nangcuctvc.com` → Settings → Public Access → disable any
   public access / custom domain binding directly on the bucket. After
   this step the bucket is unreachable by anyone except through this
   Worker.
2. **Create the Worker**:
   ```bash
   cd infrastructure/cloudflare/r2-media-worker
   npm install
   cp wrangler.toml.example wrangler.toml
   # edit wrangler.toml: set bucket_name to your real R2 bucket name
   npx wrangler deploy
   ```
3. **Set the signing secret** (must exactly match
   `CLOUDFLARE_R2_SIGNING_SECRET` in this project's `.env` /
   `TUBE_CORE_R2_SIGNING_SECRET` in WordPress):
   ```bash
   npx wrangler secret put R2_SIGNING_SECRET
   # paste the same value used for CLOUDFLARE_R2_SIGNING_SECRET
   ```
   Generate a real production value with `openssl rand -hex 32` if you
   haven't already — never reuse this repo's local-dev `.env` value in
   production, and never commit the real value anywhere.
4. **Point `media.nangcuctvc.com` at this Worker** instead of directly
   at the bucket: Cloudflare dashboard → Workers & Pages → this Worker
   → Settings → Triggers → Custom Domains → add `media.nangcuctvc.com`.
   (If that hostname is currently configured as an R2 custom domain
   directly on the bucket, remove that binding first — a hostname can't
   point at both.)
5. **DNS**: adding a Worker custom domain in the dashboard manages the
   necessary DNS record automatically (it creates/updates a proxied CNAME
   under the hood) — no manual DNS edit is normally required. Confirm in
   DNS settings that `media.nangcuctvc.com` still exists and is proxied
   (orange cloud) after step 4.
6. **Verify**: a request to `media.nangcuctvc.com/<any-real-object-key>`
   with *no* `?exp=&sig=` must now return `403` (proof the bucket itself
   is no longer directly reachable and the Worker is in front of it). A
   freshly-generated signed URL from WordPress (e.g. re-save a video's
   R2 object key in wp-admin, then copy the URL `network tab` shows the
   `<video>` element loading) must return `200`/`206` and play/seek
   normally.

No changes to the R2 S3 API credentials are needed anywhere — this
Worker only uses the R2 binding (`env.MEDIA_BUCKET`), never the S3 API,
so no S3 secret ever needs to exist in this Worker, WordPress, or a
browser.

## Local testing

```bash
npm install
npm test
```

`test/index.test.mjs` covers the Worker's pure logic (object-key
decoding, HMAC signature verification, Range-header parsing) with plain
Node — no Cloudflare account, R2 bucket, or network access required.
It cannot exercise the R2 binding itself or a real deployed custom
domain; that part can only be verified against a real Cloudflare
account (see "Required Cloudflare configuration" above).

`npm run dev` (`wrangler dev`) runs the Worker locally against
Cloudflare's Miniflare simulator if you want to test the full
request/response flow (including a local-only R2 binding) before
deploying — see [Cloudflare's Workers local development
docs](https://developers.cloudflare.com/workers/development-testing/)
for `wrangler dev`'s R2 binding support.
