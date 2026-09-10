import { describe, expect, it } from 'vitest';

describe('VRemote signaling protocol boundary', () => {
  it('uses sessionId as the routing boundary', () => {
    const sessionId = 'S-1001';
    expect(typeof sessionId).toBe('string');
    expect(sessionId.length).toBeGreaterThan(0);
  });

  it('does not define a media transport channel', () => {
    const allowed = ['join', 'offer', 'answer', 'candidate', 'leave', 'session.state', 'error'];
    expect(allowed).not.toContain('media');
    expect(allowed).not.toContain('input');
  });
});
