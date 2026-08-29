import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import {
    isValidObjectKey,
    decodeObjectKeyFromPath,
    verifySignature,
    parseRange,
} from '../src/index.js';

const SECRET = 'test-secret-do-not-use-in-production';

function sign(objectKey, expiresAt, secret = SECRET) {
    return createHmac('sha256', secret).update(`${objectKey}\n${expiresAt}`).digest('hex');
}

test('isValidObjectKey rejects empty, path-traversal, and control characters', () => {
    assert.equal(isValidObjectKey(''), false);
    assert.equal(isValidObjectKey('../secret.mp4'), false);
    assert.equal(isValidObjectKey('foo/../bar.mp4'), false);
    assert.equal(isValidObjectKey('foo\x00bar.mp4'), false);
    assert.equal(isValidObjectKey('normal-video.mp4'), true);
    assert.equal(isValidObjectKey('folder/nested-video.mp4'), true);
});

test('decodeObjectKeyFromPath reverses R2MediaUrlNormalizer::public_url()\'s per-segment rawurlencode()', () => {
    // PHP: rawurlencode('Choi Em Ngoc My.mp4') === 'Choi%20Em%20Ngoc%20My.mp4'
    assert.equal(decodeObjectKeyFromPath('/Choi%20Em%20Ngoc%20My.mp4'), 'Choi Em Ngoc My.mp4');
    // Vietnamese combining-accent Unicode, percent-encoded UTF-8 bytes.
    assert.equal(decodeObjectKeyFromPath('/EM%20Tu%CC%81.mp4'), 'EM Tú.mp4');
    // '/' is a real path separator, never itself percent-encoded -- must survive.
    assert.equal(decodeObjectKeyFromPath('/folder/nested-video.mp4'), 'folder/nested-video.mp4');
});

test('decodeObjectKeyFromPath returns null for malformed percent-encoding', () => {
    assert.equal(decodeObjectKeyFromPath('/broken%'), null);
    assert.equal(decodeObjectKeyFromPath('/broken%zz'), null);
});

test('verifySignature accepts a correctly-signed object key and expiry', async () => {
    const exp = Math.floor(Date.now() / 1000) + 600;
    const sig = sign('video.mp4', exp);

    assert.equal(await verifySignature(SECRET, 'video.mp4', exp, sig), true);
});

test('verifySignature rejects a signature for a different object key', async () => {
    const exp = Math.floor(Date.now() / 1000) + 600;
    const sig = sign('video.mp4', exp);

    assert.equal(await verifySignature(SECRET, 'other-video.mp4', exp, sig), false);
});

test('verifySignature rejects a signature for a different (tampered) expiry', async () => {
    const exp = Math.floor(Date.now() / 1000) + 600;
    const sig = sign('video.mp4', exp);

    assert.equal(await verifySignature(SECRET, 'video.mp4', exp + 1, sig), false);
});

test('verifySignature rejects a signature produced with the wrong secret', async () => {
    const exp = Math.floor(Date.now() / 1000) + 600;
    const sig = sign('video.mp4', exp, 'wrong-secret');

    assert.equal(await verifySignature(SECRET, 'video.mp4', exp, sig), false);
});

test('verifySignature rejects malformed (non-hex, odd-length) signatures without throwing', async () => {
    const exp = Math.floor(Date.now() / 1000) + 600;

    assert.equal(await verifySignature(SECRET, 'video.mp4', exp, 'not-hex!!'), false);
    assert.equal(await verifySignature(SECRET, 'video.mp4', exp, 'abc'), false);
    assert.equal(await verifySignature(SECRET, 'video.mp4', exp, ''), false);
});

test('verifySignature is deterministic: same inputs always produce a verifiable signature', async () => {
    const exp = 1893456000;
    const sigA = sign('deterministic.mp4', exp);
    const sigB = sign('deterministic.mp4', exp);

    assert.equal(sigA, sigB);
    assert.equal(await verifySignature(SECRET, 'deterministic.mp4', exp, sigA), true);
});

test('parseRange returns null (serve whole object) for no Range header', () => {
    assert.equal(parseRange(null, 1000), null);
    assert.equal(parseRange('', 1000), null);
});

test('parseRange returns null (serve whole object) for a multi-range request', () => {
    assert.equal(parseRange('bytes=0-99,200-299', 1000), null);
});

test('parseRange parses a bounded range', () => {
    assert.deepEqual(parseRange('bytes=0-99', 1000), { offset: 0, length: 100, end: 99 });
});

test('parseRange parses an open-ended range', () => {
    assert.deepEqual(parseRange('bytes=900-', 1000), { offset: 900, length: 100, end: 999 });
});

test('parseRange parses a suffix range', () => {
    assert.deepEqual(parseRange('bytes=-100', 1000), { offset: 900, length: 100, end: 999 });
});

test('parseRange clamps an end beyond the object size', () => {
    assert.deepEqual(parseRange('bytes=900-5000', 1000), { offset: 900, length: 100, end: 999 });
});

test('parseRange reports unsatisfiable for a start beyond the object size', () => {
    assert.equal(parseRange('bytes=5000-6000', 1000), 'unsatisfiable');
});

test('parseRange returns null for a malformed Range header', () => {
    assert.equal(parseRange('bytes=abc-def', 1000), null);
    assert.equal(parseRange('not-a-range', 1000), null);
});
