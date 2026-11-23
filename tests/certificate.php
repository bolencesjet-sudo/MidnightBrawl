<?php
// Test-only localhost certificate, returned to the isolated SMTP test in memory.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$options=['config'=>__DIR__.'/openssl.cnf','digest_alg'=>'sha256','private_key_bits'=>2048];
$key=openssl_pkey_new($options);
$csr=openssl_csr_new(['commonName'=>'localhost'],$key,$options);
$cert=openssl_csr_sign($csr,null,$key,1,$options);
openssl_pkey_export($key,$private,null,$options); openssl_x509_export($cert,$public);
echo json_encode(['key'=>$private,'cert'=>$public],JSON_THROW_ON_ERROR);
