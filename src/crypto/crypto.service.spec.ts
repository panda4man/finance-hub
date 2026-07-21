import { ConfigService } from '@nestjs/config';
import { randomBytes } from 'crypto';
import { CryptoService } from './crypto.service';

function makeService(key: string): CryptoService {
  const config = { get: () => key } as unknown as ConfigService<any, true>;
  return new CryptoService(config);
}

describe('CryptoService', () => {
  const key = randomBytes(32).toString('base64');
  const otherKey = randomBytes(32).toString('base64');
  let crypto: CryptoService;

  beforeEach(() => {
    crypto = makeService(key);
  });

  it('round-trips plaintext through encrypt/decrypt', () => {
    const plaintext = 'access-sandbox-abc123';
    const envelope = crypto.encrypt(plaintext);
    expect(crypto.decrypt(envelope)).toBe(plaintext);
  });

  it('produces a different envelope for the same plaintext on each call (random IV)', () => {
    const plaintext = 'same-token';
    const first = crypto.encrypt(plaintext);
    const second = crypto.encrypt(plaintext);
    expect(first).not.toBe(second);
    expect(crypto.decrypt(first)).toBe(plaintext);
    expect(crypto.decrypt(second)).toBe(plaintext);
  });

  it('stores the envelope in the versioned v1:iv:tag:ciphertext format', () => {
    const envelope = crypto.encrypt('token');
    const parts = envelope.split(':');
    expect(parts).toHaveLength(4);
    expect(parts[0]).toBe('v1');
  });

  it('throws when the ciphertext has been tampered with', () => {
    const envelope = crypto.encrypt('token');
    const [version, iv, tag, ciphertext] = envelope.split(':');
    const tamperedByte = Buffer.from(ciphertext, 'base64');
    tamperedByte[0] = tamperedByte[0] ^ 0xff;
    const tampered = [version, iv, tag, tamperedByte.toString('base64')].join(':');

    expect(() => crypto.decrypt(tampered)).toThrow();
  });

  it('throws when the auth tag has been tampered with', () => {
    const envelope = crypto.encrypt('token');
    const [version, iv, tag, ciphertext] = envelope.split(':');
    const tamperedTag = Buffer.from(tag, 'base64');
    tamperedTag[0] = tamperedTag[0] ^ 0xff;
    const tampered = [version, iv, tamperedTag.toString('base64'), ciphertext].join(':');

    expect(() => crypto.decrypt(tampered)).toThrow();
  });

  it('throws when decrypting with the wrong key', () => {
    const envelope = crypto.encrypt('token');
    const wrongKeyCrypto = makeService(otherKey);

    expect(() => wrongKeyCrypto.decrypt(envelope)).toThrow();
  });

  it('throws on a malformed envelope', () => {
    expect(() => crypto.decrypt('not-a-valid-envelope')).toThrow('Malformed encryption envelope');
  });
});
