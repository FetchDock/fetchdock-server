<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;

/**
 * Resource short names become JSON-LD terms and IRIs in the entrypoint context. JSON-LD clients, such as
 * the admin's api-doc-parser, reject the whole API documentation when one of them contains whitespace
 * ("a @context @id value must be an absolute IRI").
 */
class EntrypointContextTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    public function testEntrypointTermsAreValidIris(): void
    {
        $client = static::createClient();

        $entrypoint = $client->request('GET', '/', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        $context = $client->request('GET', '/contexts/Entrypoint', ['headers' => ['Accept' => 'application/ld+json']])->toArray()['@context'];

        $this->assertArrayHasKey('downloaderVersion', $entrypoint);
        foreach ($context as $term => $definition) {
            $this->assertDoesNotMatchRegularExpression('/\s/', $term, "Entrypoint term '$term' contains whitespace");
            if (is_array($definition) && isset($definition['@id'])) {
                $this->assertDoesNotMatchRegularExpression('/\s/', $definition['@id'], "Entrypoint term '$term' maps to an IRI with whitespace");
            }
        }
    }
}
