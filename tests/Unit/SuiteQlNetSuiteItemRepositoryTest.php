<?php

use App\Labels\DataSources\NetSuite\SuiteQlNetSuiteItemRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Searsandrew\BriarRose\BriarRoseManager;
use Tests\TestCase;

uses(TestCase::class);

test('the repository looks up an active item by exact part number', function () {
    Http::fake([
        'https://test-account.suitetalk.api.netsuite.com/*' => Http::response([
            'items' => [[
                'part_number' => "MFG'023",
                'part_description' => 'Replacement filter assembly',
                'upc' => '036000291452',
            ]],
        ]),
    ]);
    $repository = new SuiteQlNetSuiteItemRepository(testBriarRoseManager());

    expect($repository->findByPartNumber("MFG'023"))->toBe([
        'part_number' => "MFG'023",
        'part_description' => 'Replacement filter assembly',
        'upc' => '036000291452',
    ]);

    Http::assertSent(function (Request $request): bool {
        $query = $request->data()['q'] ?? '';

        return $request->method() === 'POST'
            && str_contains($request->url(), '/services/rest/query/v1/suiteql?limit=1')
            && str_contains($query, "itemid = 'MFG''023'")
            && str_contains($query, "isinactive = 'F'");
    });
});

test('the repository returns null when netsuite has no matching item', function () {
    Http::fake([
        'https://test-account.suitetalk.api.netsuite.com/*' => Http::response(['items' => []]),
    ]);

    expect((new SuiteQlNetSuiteItemRepository(testBriarRoseManager()))->findByPartNumber('MISSING'))->toBeNull();
});

function testBriarRoseManager(): BriarRoseManager
{
    config()->set('briar-rose.rest.retries.enabled', false);

    return new BriarRoseManager(
        account: 'test-account',
        consumerKey: 'consumer-key',
        consumerSecret: 'consumer-secret',
        tokenId: 'token-id',
        tokenSecret: 'token-secret',
        restletBaseUrl: null,
        restBaseUrl: null,
    );
}
