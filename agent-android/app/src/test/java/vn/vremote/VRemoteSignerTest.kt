package vn.vremote

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertTrue

class VRemoteSignerTest {
    @Test fun sessionVector() {
        val c = VRemoteSigner.canonicalSessionEnable("DEV-001", "S-1001", "view", 1800, "nonce-abc", 1760000000)
        assertEquals("DEV-001\nS-1001\nview\n1800\nnonce-abc\n1760000000", c)
        assertEquals("+z47Fx1AXfmSNSFdKlyK6xPRnPivKGLFMrKuDIIEtlY=", VRemoteSigner.sign("dev-secret-123", c))
    }

    @Test fun commandVectorA() {
        val h = VRemoteSigner.argsHash("{\"target\":\"vbridge\"}")
        assertEquals("c5f85fa5aba702326ec0da5d3cd57b8e98bb2e6634867d7c13451f5c863c668e", h)
        val c = VRemoteSigner.canonicalCommand("DEV-001", "CMD-0001", "restart-app", h, "nonce-xyz", 1760000000)
        assertEquals("/lAXmjDUVdnVSZ4RM+T323imtc0UHthJ8m4159gYX5A=", VRemoteSigner.sign("dev-secret-123", c))
    }

    @Test fun commandVectorB() {
        val c = VRemoteSigner.canonicalCommand("DEV-001", "CMD-0002", "health", "", "nonce-000", 1760000000)
        assertEquals("", VRemoteSigner.argsHash(null))
        assertEquals("n5obzyY/A5PsQ1mhLHXqUJsmAK0OB9m/vvB+kBUisUY=", VRemoteSigner.sign("dev-secret-123", c))
    }

    @Test fun tamperingFails() {
        val c = VRemoteSigner.canonicalSessionEnable("DEV-001", "S-1001", "view", 1800, "nonce-abc", 1760000000)
        val s = VRemoteSigner.sign("dev-secret-123", c)
        assertTrue(VRemoteSigner.verify("dev-secret-123", c, s))
        assertFalse(VRemoteSigner.verify("wrong-secret", c, s))
        assertFalse(VRemoteSigner.verify("dev-secret-123", c + "x", s))
    }
}
