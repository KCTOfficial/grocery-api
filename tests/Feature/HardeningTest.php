<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Feature;

use GroceryRestable\Auth\AuthContext;
use GroceryRestable\Resource\Conditions;
use GroceryRestable\Support\Jwt;
use GroceryRestable\Support\Uuid;
use GroceryRestable\Tests\Support\ApiTestCase;

/**
 * UUID primary keys, rate limiting, JSON logs and the fixes of the API security audit.
 */
final class HardeningTest extends ApiTestCase
{
    private const SECRET = 'jwt-secret-jwt-secret-jwt-secret-jwt-secret';

    protected function settings(): array
    {
        return [
            'auth_mode' => 'any',
            'jwt_secret' => self::SECRET,
            'rate_limit_requests' => 1000,
            'rate_limit_anon_requests' => 1000,
            'default_permissions' => ['anon' => ['select'], 'authenticated' => ['select', 'insert', 'update', 'delete']],
        ];
    }

    private function bearer(array $claims): array
    {
        return ['Authorization' => 'Bearer ' . Jwt::encode($claims + ['exp' => time() + 60], self::SECRET)];
    }

    private function serviceKey(): array
    {
        return ['apikey' => $this->api->store()->createApiKey('Service', 'service_role')];
    }

    // ------------------------------------------------------------------ UUID primary keys

    public function testUuidColumnsGetAGeneratedId(): void
    {
        $this->db()->exec('CREATE TABLE notes (id UUID PRIMARY KEY, title TEXT NOT NULL)');
        $this->api->schema()->refresh();
        $this->api->resource('notes');

        [$status, $body] = $this->call('POST', 'notes', ['title' => 'first'], $this->serviceKey() + ['Prefer' => 'return=representation']);
        self::assertSame(201, $status);
        self::assertTrue(Uuid::isValid($body['id']));
        self::assertSame('4', $body['id'][14], 'auto generator is UUID v4');
    }

    public function testExplicitUuid7GeneratorAndValidation(): void
    {
        $this->db()->exec('CREATE TABLE tickets (code VARCHAR(36) PRIMARY KEY, subject TEXT NOT NULL)');
        $this->api->schema()->refresh();
        $this->api->resource('tickets')->setIdGenerator('uuid7');
        $key = $this->serviceKey();

        [$status, $body] = $this->call('POST', 'tickets', ['subject' => 'a'], $key + ['Prefer' => 'return=representation']);
        self::assertSame(201, $status);
        self::assertSame('7', $body['code'][14]);
        [, $second] = $this->call('POST', 'tickets', ['subject' => 'b'], $key + ['Prefer' => 'return=representation']);
        self::assertGreaterThan($body['code'], $second['code'], 'UUID v7 sort by creation time');

        self::assertSame(422, $this->call('POST', 'tickets', ['code' => 'not-a-uuid', 'subject' => 'c'], $key)[0]);
        $custom = Uuid::v4();
        self::assertSame(201, $this->call('POST', 'tickets', ['code' => $custom, 'subject' => 'd'], $key)[0], 'clients may send their own UUID');
    }

    public function testUuidHelpers(): void
    {
        self::assertTrue(Uuid::isValid(Uuid::v4()));
        self::assertTrue(Uuid::isValid(Uuid::v7()));
        self::assertLessThan(Uuid::v7(2_000_000_000_000), Uuid::v7(1_000_000_000_000));
        self::assertFalse(Uuid::isValid('1234'));
    }

    // ------------------------------------------------------------------ rate limiting

    public function testAnonymousRateLimitPerIp(): void
    {
        $this->api->store()->saveSettings(['rate_limit_anon_requests' => 2]);
        $this->api->resource('offices');

        [$status, , $response] = $this->call('GET', 'offices');
        self::assertSame(200, $status);
        self::assertSame('2', $response->getHeader('X-RateLimit-Limit'));
        self::assertSame('1', $response->getHeader('X-RateLimit-Remaining'));
        self::assertSame(200, $this->call('GET', 'offices')[0]);

        [$status, $body, $response] = $this->call('GET', 'offices');
        self::assertSame(429, $status);
        self::assertSame('rate_limited', $body['error']['code']);
        self::assertNotNull($response->getHeader('Retry-After'));
    }

    public function testResourceLimitServiceExemptionAndKeyGuessing(): void
    {
        $this->api->resource('offices')->setRateLimit(1, 60);
        $this->api->resource('employees');
        $editor = ['apikey' => $this->api->store()->createApiKey('Editor', 'authenticated')];

        self::assertSame(200, $this->call('GET', 'offices', null, $editor)[0]);
        self::assertSame(429, $this->call('GET', 'offices', null, $editor)[0]);
        self::assertSame(200, $this->call('GET', 'employees', null, $editor)[0], 'other resources use the global limit');

        $service = $this->serviceKey();
        foreach (range(1, 3) as $ignored) {
            self::assertSame(200, $this->call('GET', 'offices', null, $service)[0], 'service_role is exempt');
        }

        $this->api->store()->saveSettings(['rate_limit_anon_requests' => 3]);
        $codes = array_map(fn (): int => $this->call('GET', 'employees', null, ['apikey' => 'gr_guess'])[0], range(1, 5));
        self::assertSame([401, 401, 401, 429, 429], $codes, 'failed authentications count against the client IP');
    }

    // ------------------------------------------------------------------ logs

    public function testRequestsAreLoggedAsJsonPerDay(): void
    {
        $this->api->store()->saveSettings(['allow_query_apikey' => true]);
        $this->api->resource('offices');
        $key = $this->api->store()->createApiKey('Reader', 'authenticated');
        $this->call('GET', 'offices?limit=1&apikey=' . $key);
        $this->call('GET', 'nope');

        $logs = $this->api->logs();
        $day = date('Y-m-d');
        self::assertSame($day, $logs->days()[0]['day']);
        $raw = (string) file_get_contents($logs->path($day));
        self::assertStringNotContainsString($key, $raw, 'API keys are redacted');
        self::assertCount(2, array_filter(explode("\n", $raw)));

        $result = $logs->read($day);
        self::assertSame(2, $result['total']);
        self::assertSame(404, $result['entries'][0]['status'], 'newest first');
        self::assertSame('offices', $result['entries'][1]['resource']);
        self::assertSame('authenticated', $result['entries'][1]['role']);
        self::assertSame(1, $logs->read($day, ['status' => '4xx'])['total']);
        self::assertSame(['2xx' => 1, '4xx' => 1], $result['stats']['statuses']);

        $this->api->store()->saveSettings(['log_requests' => 'errors']);
        $this->call('GET', 'offices', null, ['apikey' => $key]);
        self::assertSame(2, $logs->read($day)['total'], 'successful requests are skipped in "errors" mode');

        self::assertTrue($logs->delete($day));
        self::assertSame([], $logs->days());
    }

    public function testInvalidLogDayIsRejected(): void
    {
        $this->expectException(\GroceryRestable\Exception\ApiException::class);
        $this->api->logs()->path('../grocery-restable');
    }

    // ------------------------------------------------------------------ audit fixes

    public function testScopesAreEnforcedOnWrites(): void
    {
        $this->api->resource('secrets')
            ->setPermissions('authenticated', ['select', 'insert', 'update'])
            ->scope(static fn (Conditions $q, AuthContext $auth) => $q->where('owner_id', $auth->userId()));
        $alice = $this->bearer(['sub' => 'alice']);

        self::assertSame(201, $this->call('POST', 'secrets', ['owner_id' => 'alice', 'note' => 'mine'], $alice)[0]);
        self::assertSame(403, $this->call('POST', 'secrets', ['owner_id' => 'bob', 'note' => 'planted'], $alice)[0]);
        self::assertSame(403, $this->call('PATCH', 'secrets/1', ['owner_id' => 'bob'], $alice)[0]);
        self::assertSame('alice', $this->db()->query('SELECT owner_id FROM secrets WHERE id = 1')->fetchColumn(), 'rolled back');
        self::assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM secrets WHERE note = 'planted'")->fetchColumn());

        // A token without "sub" must not match rows by "owner_id IS NULL".
        [$status, $rows] = $this->call('GET', 'secrets', null, $this->bearer([]));
        self::assertSame([200, []], [$status, $rows]);
    }

    public function testStrictScopeConditions(): void
    {
        $items = Conditions::forScope()->where('owner_id', null)->items();
        self::assertSame('in', $items[0]['operator']);
        self::assertSame([], $items[0]['value']);
        self::assertSame('is null', (new Conditions())->where('owner_id', null)->items()[0]['operator']);
    }

    public function testExcludedTablesCannotBeReachedThroughRelations(): void
    {
        $this->api->store()->saveSettings(['auto_expose' => true, 'auto_exclude_tables' => ['employees']]);
        $relation = null;
        foreach ($this->api->registry()->find('customers')->getRelations() as $candidate) {
            if ($candidate->table === 'employees') {
                $relation = $candidate->name;
            }
        }
        self::assertNotNull($relation);
        self::assertSame(403, $this->call('GET', 'customers?select=customerNumber,' . $relation . '(*)')[0]);
        self::assertSame(403, $this->call('GET', 'customers?select=customerNumber&' . $relation . '.email=like.*a*')[0]);
    }

    public function testRelationFiltersAndOptionsNeedReadPermissionOnTheTarget(): void
    {
        $this->api->resource('customers')->setRelation('salesRepEmployeeNumber', 'employees', 'email', null, null, 'rep');
        $this->api->resource('employees')->setPermissions('anon', []);

        self::assertSame(401, $this->call('GET', 'customers?select=customerNumber&rep.salary=gt.5000')[0]);
        self::assertSame(401, $this->call('GET', 'customers/_options/salesRepEmployeeNumber')[0]);
    }

    public function testUpsertRespectsDisabledUpdate(): void
    {
        $this->api->resource('film')->unsetEdit();
        [$status] = $this->call('POST', 'film', ['film_id' => 1, 'title' => 'Changed'], $this->serviceKey() + ['Prefer' => 'resolution=merge-duplicates']);
        self::assertSame(405, $status);
        self::assertSame('Academy Dinosaur', $this->db()->query('SELECT title FROM film WHERE film_id = 1')->fetchColumn());
    }

    public function testOpenApiOnlyDescribesWhatTheCallerCanUse(): void
    {
        $this->api->resource('offices');
        $this->api->resource('employees')->setPermissions('anon', []);

        [, $anon] = $this->call('GET', 'openapi.json');
        self::assertArrayHasKey('/offices', $anon['paths']);
        self::assertArrayNotHasKey('/employees', $anon['paths']);
        [, $service] = $this->call('GET', 'openapi.json', null, $this->serviceKey());
        self::assertArrayHasKey('/employees', $service['paths']);
    }

    public function testJwtHardening(): void
    {
        $this->api->resource('offices');
        $token = static fn (array $claims): array => ['Authorization' => 'Bearer ' . Jwt::encode($claims, self::SECRET)];

        self::assertSame(401, $this->call('GET', 'offices', null, $this->bearer(['role' => 'service_role']))[0], 'tokens cannot grant service_role');
        self::assertSame(401, $this->call('GET', 'offices', null, $token(['sub' => 'x']))[0], 'exp is required');
        self::assertSame(401, $this->call('GET', 'offices', null, $token(['sub' => 'x', 'exp' => 'never']))[0]);
        self::assertSame(401, $this->call('GET', 'offices', null, ['Authorization' => 'Bearer ' . Jwt::encode(['exp' => time() + 60], self::SECRET, 'HS512')])[0], 'configured algorithm only');

        $this->api->store()->saveSettings(['jwt_allow_service_role' => true, 'jwt_require_exp' => false]);
        self::assertSame(200, $this->call('GET', 'offices', null, $token(['role' => 'service_role']))[0]);
    }

    public function testQueryComplexityLimits(): void
    {
        $this->api->resource('offices');
        $nested = str_repeat('and(', 12) . 'city.eq.x' . str_repeat(')', 12);
        self::assertSame(400, $this->call('GET', 'offices?or=(' . $nested . ')')[0]);
        self::assertSame(414, $this->call('GET', 'offices?city=eq.' . str_repeat('a', 20000))[0]);
    }

    public function testTheJavaScriptSdkIsServedByTheApi(): void
    {
        [$status, , $response] = $this->call('GET', 'sdk.js');
        self::assertSame(200, $status);
        self::assertStringStartsWith('application/javascript', (string) $response->getHeader('Content-Type'));
        self::assertStringContainsString('export default class GroceryRestable', $response->getBody());
    }

    public function testMethodOverrideOnlyForForms(): void
    {
        $this->api->resource('offices');
        [$status] = $this->call('POST', 'offices', ['officeCode' => '9', 'city' => 'Rome', 'country' => 'Italy'], $this->serviceKey() + ['X-HTTP-Method-Override' => 'DELETE']);
        self::assertSame(201, $status, 'a JSON POST stays a POST');
    }
}
