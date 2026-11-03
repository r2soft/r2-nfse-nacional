<?php

namespace R2soft\NfseNacional\Tests;

/** PFX autoassinado gerado na hora: o repositório é público e não guarda certificado. */
final class CertificadoDeTeste
{
    public static function pfx(string $senha): string
    {
        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr   = openssl_csr_new(['commonName' => 'EMPRESA FICTICIA LTDA:11222333000181'], $chave, ['digest_alg' => 'sha256']);
        $cert  = openssl_csr_sign($csr, null, $chave, 30, ['digest_alg' => 'sha256']);
        openssl_pkcs12_export($cert, $pfx, $chave, $senha);
        return $pfx;
    }

    public static function certificadoPem(string $pfx, string $senha): string
    {
        openssl_pkcs12_read($pfx, $certs, $senha);
        return $certs['cert'];
    }
}
