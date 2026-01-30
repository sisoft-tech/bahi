<?php
// include/share_token.php


function b64url_enc(string $s): string {
  return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function b64url_dec(string $s): string {
  $s = strtr($s, '-_', '+/');
  $pad = strlen($s) % 4;
  if ($pad) $s .= str_repeat('=', 4 - $pad);
  $out = base64_decode($s, true);
  if ($out === false) throw new RuntimeException("Bad base64");
  return $out;
}

function share_secret(): string {
  // Put this in include/param.php or env; MUST be long random.
//  if (!empty($_ENV['DC_PRINT_SECRET'])) return (string)$_ENV['DC_PRINT_SECRET'];
//  if (defined('DC_PRINT_SECRET')) return (string)DC_PRINT_SECRET;

  // DO NOT keep this in production
  return 'delivery-challan';
}

function make_print_token(int $biz_id, int $dc_id, int $days_valid = 90): string {
  $payload = [
    'biz_id'  => $biz_id,
    'dc_id'   => $dc_id,
    'purpose' => 'print',
    'exp'     => time() + ($days_valid * 86400),
  ];

  $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
  $p = b64url_enc($json);

  $sig_bin = hash_hmac('sha256', $p, share_secret(), true);
  $s = b64url_enc($sig_bin);

  return $p . '.' . $s;
}

function verify_print_token(string $token): array {
  $parts = explode('.', $token);
  if (count($parts) !== 2) throw new RuntimeException("Invalid token");

  [$p, $s] = $parts;

  $sig  = b64url_dec($s);
  $calc = hash_hmac('sha256', $p, share_secret(), true);

  if (!hash_equals($calc, $sig)) throw new RuntimeException("Invalid signature");

  $payload = json_decode(b64url_dec($p), true);
  if (!is_array($payload)) throw new RuntimeException("Bad payload");

  if (($payload['exp'] ?? 0) < time()) throw new RuntimeException("Link expired");
  if (($payload['purpose'] ?? '') !== 'print') throw new RuntimeException("Wrong purpose");

  $biz_id = (int)($payload['biz_id'] ?? 0);
  $dc_id  = (int)($payload['dc_id'] ?? 0);
  if ($biz_id <= 0 || $dc_id <= 0) throw new RuntimeException("Bad ids");

  return $payload; // biz_id, dc_id
}
?>
