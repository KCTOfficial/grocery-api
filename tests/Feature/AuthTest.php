<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Feature;

use GroceryRestable\Auth\AuthContext;
use GroceryRestable\Resource\Conditions;
use GroceryRestable\Support\Jwt;
use GroceryRestable\Tests\Support\ApiTestCase;

final class AuthTest extends ApiTestCase
{
    private const SECRET = 'jwt-secret-jwt-secret-jwt-secret-jwt-secret';

    protected function settings(): array
    {
        return ['auth_mode' => 'any', 'jwt_secret' => self::SECRET];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->api->resource('offices')->setPermissions(['anon' => ['select'], 'editor' => ['select', 'insert']]);
        $this->api->resource('employees');
        $this->api->resource('secrets')
            ->setPermissions('authenticated', ['select', 'update'])
            ->scope(static fn (Conditions $q, AuthContext $auth) => $q->where('owner_id', (string) $auth->claim('sub', '')));
    }

    private function bearer(array $claims): array
    {
        return ['Authorization' => 'Bearer ' . Jwt::encode($claims + ['exp' => time() + 60], self::SECRET)];
    }

    public function testAnonymousAccessFollowsPermissions(): void
    {
        self::assertSame(200, $this->call('GET', 'offices')[0]);
        self::assertSame(401, $this->call('POST', 'offices', ['officeCode' => '9', 'city' => 'x', 'country' => 'y'])[0]);
        self::assertSame(401, $this->call('GET', 'employees')[0], 'Default permissions do not grant anything to anon');
    }

    public function testApiKeys(): void
    {
        $editor = $this->api->store()->createApiKey('Editor', 'editor');
        $service = $this->api->store()->createApiKey('Service', 'service_role');

        self::assertSame(201, $this->call('POST', 'offices', ['officeCode' => '9', 'city' => 'x', 'country' => 'y'], ['apikey' => $editor])[0]);
        self::assertSame(403, $this->call('DELETE', 'offices/9', null, ['X-API-Key' => $editor])[0]);
        self::assertSame(204, $this->call('DELETE', 'offices/9', null, ['Authorization' => 'Bearer ' . $service])[0]);
        self::assertSame(401, $this->call('GET', 'offices', null, ['apikey' => 'gr_invalid'])[0]);

        $this->api->store()->revokeApiKey((int) $this->api->store()->apiKeys()[0]['id']);
        self::assertSame(401, $this->call('GET', 'offices', null, ['apikey' => $editor])[0]);
    }

    public function testQueryStringKeyOnlyWhenEnabled(): void
    {
        $key = $this->api->store()->createApiKey('Key', 'authenticated');
        self::assertSame(401, $this->call('GET', 'employees?apikey=' . $key)[0]);

        $this->api->store()->saveSettings(['allow_query_apikey' => true]);
        self::assertSame(200, $this->call('GET', 'employees?apikey=' . $key)[0]);
    }

    public function testJwtRolesAndRowLevelSecurity(): void
    {
        [$status, $rows] = $this->call('GET', 'secrets', null, $this->bearer(['sub' => 'alice', 'role' => 'authenticated']));
        self::assertSame(200, $status);
        self::assertSame(['alice note'], array_column($rows, 'note'));

        self::assertSame(404, $this->call('GET', 'secrets/2', null, $this->bearer(['sub' => 'alice']))[0]);
        self::assertSame(404, $this->call('PATCH', 'secrets/2', ['note' => 'x'], $this->bearer(['sub' => 'alice']))[0]);
        self::assertSame(200, $this->call('PATCH', 'secrets/1', ['note' => 'x'], $this->bearer(['sub' => 'alice']))[0]);
        self::assertSame(403, $this->call('DELETE', 'secrets/1', null, $this->bearer(['sub' => 'alice']))[0]);
    }

    public function testJwtClaimPathAndInvalidTokens(): void
    {
        $this->api->store()->saveSettings(['jwt_role_claim' => 'app_metadata.role']);
        self::assertSame(201, $this->call('POST', 'offices', ['officeCode' => '8', 'city' => 'x', 'country' => 'y'], $this->bearer(['app_metadata' => ['role' => 'editor']]))[0]);

        self::assertSame(401, $this->call('GET', 'offices', null, ['Authorization' => 'Bearer ' . Jwt::encode(['role' => 'service_role'], 'wrong-secret-wrong-secret-wrong-secret')])[0]);
        self::assertSame(401, $this->call('GET', 'offices', null, $this->bearer(['app_metadata' => ['role' => 'bad role!']]))[0]);
    }

    public function testModesRestrictCredentialTypes(): void
    {
        $key = $this->api->store()->createApiKey('Key', 'authenticated');
        $this->api->store()->saveSettings(['auth_mode' => 'jwt']);
        self::assertSame(401, $this->call('GET', 'employees', null, ['apikey' => $key])[0]);

        $this->api->store()->saveSettings(['auth_mode' => 'api_key']);
        self::assertSame(401, $this->call('GET', 'employees', null, $this->bearer(['role' => 'authenticated']))[0]);
    }

    public function testEmbeddingRespectsTargetPermissions(): void
    {
        $this->api->resource('employees')->setPermissions('anon', ['select'])->setRelation('officeCode', 'offices', 'city');
        $this->api->resource('offices')->setPermissions('anon', []);

        self::assertSame(200, $this->call('GET', 'employees?select=lastName')[0]);
        self::assertSame(401, $this->call('GET', 'employees?select=lastName,offices(city)')[0]);
    }

    public function testTablesWithoutResourceOnlyExposeKeyAndTitle(): void
    {
        // "film" is not exposed as a resource: only its key and title columns can be embedded.
        $this->api->resource('notes', 'secrets')->setPermissions('*', ['select'])->setRelation('id', 'film', 'title', null, null, 'film');

        [$status, $rows] = $this->call('GET', 'notes?select=id,film(*)&id=eq.1');
        self::assertSame(200, $status);
        self::assertSame(['film_id' => 1, 'title' => 'Academy Dinosaur'], $rows[0]['film']);
        self::assertSame(400, $this->call('GET', 'notes?select=id,film(rating)')[0]);
    }

    public function testCorsPreflight(): void
    {
        [$status, , $response] = $this->call('OPTIONS', 'offices', null, ['Origin' => 'https://app.example.com']);
        self::assertSame(204, $status);
        self::assertSame('*', $response->getHeader('Access-Control-Allow-Origin'));

        $this->api->store()->saveSettings(['cors_origins' => ['https://app.example.com']]);
        [, , $allowed] = $this->call('GET', 'offices', null, ['Origin' => 'https://app.example.com']);
        [, , $denied] = $this->call('GET', 'offices', null, ['Origin' => 'https://evil.example.com']);
        self::assertSame('https://app.example.com', $allowed->getHeader('Access-Control-Allow-Origin'));
        self::assertNull($denied->getHeader('Access-Control-Allow-Origin'));
    }
}
