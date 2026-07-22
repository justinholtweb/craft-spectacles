<?php

namespace justinholtweb\spectacles\tests\unit;

use justinholtweb\spectacles\services\similarity\PgvectorBackend;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * vectorLiteral() renders a PHP float array into the `[1,2,3]` text form
 * pgvector casts to `vector`. It is pure string work with no DB involvement,
 * so it belongs in the plain PHPUnit suite.
 */
class PgvectorLiteralTest extends TestCase
{
    private ReflectionMethod $literal;
    private PgvectorBackend $backend;

    protected function setUp(): void
    {
        $this->backend = new PgvectorBackend();
        $this->literal = new ReflectionMethod(PgvectorBackend::class, 'vectorLiteral');
        $this->literal->setAccessible(true);
    }

    private function literal(array $vector): string
    {
        return $this->literal->invoke($this->backend, $vector);
    }

    public function testFormatsASimpleVector(): void
    {
        $this->assertSame('[1,2,3]', $this->literal([1.0, 2.0, 3.0]));
    }

    public function testTrimsTrailingZerosButKeepsIntegerPart(): void
    {
        // Guards against an over-eager rtrim() eating the zeros in "100".
        $this->assertSame('[100,10,0.5]', $this->literal([100.0, 10.0, 0.5]));
    }

    public function testKeepsNegativeValues(): void
    {
        $this->assertSame('[-0.5,-1]', $this->literal([-0.5, -1.0]));
    }

    public function testRendersZeroAsZero(): void
    {
        $this->assertSame('[0,0]', $this->literal([0.0, 0.0]));
    }

    public function testEmptyVectorRendersAsEmptyBrackets(): void
    {
        $this->assertSame('[]', $this->literal([]));
    }

    public function testProducesNoScientificNotation(): void
    {
        // pgvector cannot parse "1.0E-9"; %.8f keeps it in plain decimal form.
        $literal = $this->literal([0.000000001, 123456.75]);

        $this->assertStringNotContainsStringIgnoringCase('e', $literal);
    }
}
