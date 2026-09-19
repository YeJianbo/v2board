<?php
namespace App\Services;

class NodeTlsBootstrap
{
    public static function anytls(array $settings): array
    {
        if (!empty($settings['cert_mode'])) return $settings;
        $name = $settings['server_name'] ?? 'genshin.hoyoverse.com';
        $key = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
        abort_unless($key,500,'无法生成节点证书密钥');
        $csr = openssl_csr_new(['commonName'=>$name],$key,['digest_alg'=>'sha256']);
        $cert = $csr ? openssl_csr_sign($csr,null,$key,365,['digest_alg'=>'sha256'],random_int(1,PHP_INT_MAX)) : false;
        abort_unless($cert,500,'无法签发节点证书');
        abort_unless(openssl_x509_export($cert,$pem) && openssl_pkey_export($key,$private),500,'无法导出节点证书');
        $public = openssl_pkey_get_details($key)['key'];
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s/','',$public),true);
        abort_unless($der!==false,500,'无法计算节点证书PIN');
        return array_merge($settings,['server_name'=>$name,'cert_mode'=>'remote','tls_cert'=>$pem,'tls_key'=>$private,'allow_insecure'=>1,
            'certificate_fingerprint'=>openssl_x509_fingerprint($cert,'sha256'),
            'certificate_public_key_sha256'=>[base64_encode(hash('sha256',$der,true))]]);
    }
}
