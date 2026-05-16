<?php

namespace App\Lib\Parser;

use App\Models\File;
use App\Models\Signature;
use Carbon\Carbon;

class SignatureParser extends BaseParser
{

    /**
     * @inheritDoc
     */
    public function parse(?int $parentId = null): array
    {
        $contents = file_get_contents($this->path);
        $doc = simplexml_load_string($contents);
        $doc->registerXPathNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $doc->registerXPathNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');

        $keys = $doc->xpath('//asic:XAdESSignatures/ds:Signature/ds:KeyInfo/ds:X509Data/ds:X509Certificate');
        $times = $doc->xpath('/asic:XAdESSignatures/ds:Signature/ds:Object/xades:QualifyingProperties/xades:SignedProperties/xades:SignedSignatureProperties/xades:SigningTime');
        $signatures = [];

        foreach ($keys as $n => $key) {
            $data = [];
            $x509Data =
                "-----BEGIN CERTIFICATE-----\n"
                . str_replace("\n", '', (string)$key)
                . "\n-----END CERTIFICATE-----";
            info($x509Data);
            $cert = openssl_x509_read($x509Data);
            $certData = openssl_x509_parse($cert);
            if (isset($certData['subject']['GN']) && isset($certData['subject']['SN'])) {
                $data['name'] = $certData['subject']['GN'] . ' ' . $certData['subject']['SN'];
            } else {
                $data['name'] = $certData['subject']['CN'];
            }
            $data['pno'] = $certData['subject']['serialNumber'];
            $data['signing_time'] = Carbon::parse((string)$times[$n]);
            $signatures[] = $data;
        }

        $file = File::query()->find($parentId);

        foreach ($signatures as $signature) {
            $file->signatures()->save(new Signature([
                'name' => $signature['name'],
                'pno' => $signature['pno'],
                'signing_time' => $signature['signing_time']
            ]));
        }

        return [];

    }
}
