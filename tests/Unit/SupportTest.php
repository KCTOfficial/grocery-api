<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Unit;

use GroceryRestable\Exception\ApiException;
use GroceryRestable\Support\Crypto;
use GroceryRestable\Support\Jwt;
use GroceryRestable\Support\Size;
use GroceryRestable\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function testJwtRoundTrip(): void
    {
        $token = Jwt::encode(['sub' => 'u1', 'role' => 'editor', 'exp' => time() + 60], 'secret-secret-secret-secret-secret');
        self::assertSame('editor', Jwt::decode($token, 'secret-secret-secret-secret-secret')['role']);
    }

    public function testJwtRejectsTamperingAlgNoneAndExpiry(): void
    {
        $secret = 'secret-secret-secret-secret-secret';
        $token = Jwt::encode(['role' => 'user'], $secret);
        [$h, , $s] = explode('.', $token);
        $forged = $h . '.' . rtrim(strtr(base64_encode('{"role":"service_role"}'), '+/', '-_'), '=') . '.' . $s;
        $none = rtrim(strtr(base64_encode('{"alg":"none"}'), '+/', '-_'), '=') . '.' . explode('.', $token)[1] . '.';

        foreach ([$forged, $none, Jwt::encode(['exp' => time() - 3600], $secret), Jwt::encode(['role' => 'x'], 'other-secret-other-secret-other')] as $bad) {
            try {
                Jwt::decode($bad, $secret);
                self::fail('Token should be rejected: ' . $bad);
            } catch (ApiException $e) {
                self::assertSame(401, $e->getStatus());
            }
        }
    }

    public function testCryptoRoundTripAndTamperDetection(): void
    {
        $crypto = new Crypto(Crypto::generateKey());
        $encrypted = $crypto->encrypt('db-password');
        self::assertStringStartsWith('enc:v1:', $encrypted);
        self::assertSame('db-password', $crypto->decrypt($encrypted));

        $this->expectException(\GroceryRestable\Exception\ConfigurationException::class);
        (new Crypto(Crypto::generateKey()))->decrypt($encrypted);
    }

    public function testSize(): void
    {
        self::assertSame(20 * 1024 * 1024, Size::toBytes('20MB'));
        self::assertSame(1067 * 1024, Size::toBytes('1067KB'));
        self::assertSame(5000, Size::toBytes('5000B'));
        self::assertNull(Size::toBytes(''));
    }

    public function testValidatorRules(): void
    {
        $validator = new Validator();
        $rules = [
            'email' => ['rules' => 'required|valid_email', 'label' => 'E-mail'],
            'age' => ['rules' => 'permit_empty|integer|greater_than[17]', 'label' => null],
            'code' => ['rules' => 'regex_match[/^[A-Z]{2}\d+$/]|max_length[6]', 'label' => null],
            'role' => ['rules' => 'in_list[admin,editor]', 'label' => null],
            'confirm' => ['rules' => 'matches[password]', 'label' => null],
        ];

        self::assertSame([], $validator->validate(['email' => 'a@b.co', 'age' => '', 'code' => 'AB12', 'role' => 'editor'], $rules));

        $errors = $validator->validate(['email' => 'nope', 'age' => '12', 'code' => 'ab', 'role' => 'root', 'password' => 'x', 'confirm' => 'y'], $rules);
        self::assertSame(['email', 'age', 'code', 'role', 'confirm'], array_keys($errors));
        self::assertSame('The E-mail field must contain a valid email address.', $errors['email']);
    }

    public function testValidatorSkipsAbsentOptionalFields(): void
    {
        $errors = (new Validator())->validate([], ['a' => ['rules' => 'valid_email', 'label' => null], 'b' => ['rules' => 'required', 'label' => null]]);
        self::assertSame(['b'], array_keys($errors));
    }
}
