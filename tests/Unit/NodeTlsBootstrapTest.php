<?php
namespace Tests\Unit;
use App\Services\NodeTlsBootstrap;
use Tests\TestCase;
class NodeTlsBootstrapTest extends TestCase
{
    public function test_generated_certificate_and_pin_match(): void
    {
        $s=NodeTlsBootstrap::anytls([]);
        $this->assertSame('remote',$s['cert_mode']);
        $this->assertTrue(openssl_x509_check_private_key($s['tls_cert'],$s['tls_key']));
        $this->assertSame(openssl_x509_fingerprint($s['tls_cert'],'sha256'),$s['certificate_fingerprint']);
        $pub=openssl_pkey_get_details(openssl_pkey_get_public($s['tls_cert']))['key'];
        $der=base64_decode(preg_replace('/-----[^-]+-----|\s/','',$pub));
        $this->assertSame([base64_encode(hash('sha256',$der,true))],$s['certificate_public_key_sha256']);
        $this->assertSame($s,NodeTlsBootstrap::anytls($s));
    }
}
