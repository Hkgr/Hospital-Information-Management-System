<?php

namespace Tests\Support;

use PHPUnit\Framework\AssertionFailedError;

trait AssertsOpenApi
{
    protected function resolveSchema(array $document, array $schema): array
    {
        while (isset($schema['$ref'])) {
            $path = explode('/', substr($schema['$ref'], 2));
            $schema = $document;
            foreach ($path as $part) {
                $this->assertArrayHasKey($part, $schema);
                $schema = $schema[$part];
            }
        }

        return $schema;
    }

    protected function assertMatchesSchema(array $document, array $schema, mixed $value): void
    {
        $schema = $this->resolveSchema($document, $schema);
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $variant) {
                try {
                    $this->assertMatchesSchema($document, $variant, $value);

                    return;
                } catch (AssertionFailedError) {
                    // Try the other documented response alternative.
                }
            }
            $this->fail('Response matches none of the documented alternatives.');
        }
        $types = (array) ($schema['type'] ?? []);
        if ($value === null) {
            $this->assertContains('null', $types);

            return;
        }
        if (isset($schema['const'])) {
            $this->assertSame($schema['const'], $value);
        }
        if (isset($schema['enum'])) {
            $this->assertContains($value, $schema['enum']);
        }
        if (in_array('object', $types, true)) {
            $this->assertIsArray($value);
            foreach ($schema['required'] ?? [] as $required) {
                $this->assertArrayHasKey($required, $value);
            }
            foreach ($value as $key => $item) {
                if (! isset($schema['properties'][$key]) && is_array($schema['additionalProperties'] ?? null)) {
                    $this->assertMatchesSchema($document, $schema['additionalProperties'], $item);

                    continue;
                }
                $this->assertArrayHasKey($key, $schema['properties']);
                $this->assertMatchesSchema($document, $schema['properties'][$key], $item);
            }
        } elseif (in_array('array', $types, true)) {
            $this->assertIsArray($value);
            $this->assertTrue(array_is_list($value));
            foreach ($value as $item) {
                $this->assertMatchesSchema($document, $schema['items'], $item);
            }
        } elseif (in_array('integer', $types, true)) {
            $this->assertIsInt($value);
        } elseif (in_array('boolean', $types, true)) {
            $this->assertIsBool($value);
        } elseif (in_array('string', $types, true)) {
            $this->assertIsString($value);
            if (($schema['format'] ?? '') === 'date-time') {
                $this->assertNotFalse(strtotime($value));
            }
        } else {
            $this->fail('Schema has no supported concrete type: '.json_encode($schema));
        }
    }
}
