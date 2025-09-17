<?php

namespace App\Tests\Unit\Validator;

use App\Factory\DownloaderFactory;
use App\Validator\SelectDownloader;
use App\Validator\SelectDownloaderValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class SelectDownloaderValidatorTest extends TestCase
{
    private SelectDownloaderValidator $validator;
    private DownloaderFactory $downloaderFactory;
    private ExecutionContextInterface $context;
    private ConstraintViolationBuilderInterface $violationBuilder;

    protected function setUp(): void
    {
        $this->downloaderFactory = $this->createMock(DownloaderFactory::class);
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);

        $this->validator = new SelectDownloaderValidator($this->downloaderFactory);
        $this->validator->initialize($this->context);
    }

    public function testValidateWithNullValue(): void
    {
        $constraint = new SelectDownloader();

        $this->context->expects($this->never())
            ->method('buildViolation');

        $this->validator->validate(null, $constraint);
    }

    public function testValidateWithEmptyStringValue(): void
    {
        $constraint = new SelectDownloader();

        $this->context->expects($this->never())
            ->method('buildViolation');

        $this->validator->validate('', $constraint);
    }

    public function testValidateWithValidDownloader(): void
    {
        $constraint = new SelectDownloader();
        $value = 'yt-dlp-cli';

        $this->downloaderFactory->expects($this->once())
            ->method('isValidDownloader')
            ->with($value)
            ->willReturn(true);

        $this->context->expects($this->never())
            ->method('buildViolation');

        $this->validator->validate($value, $constraint);
    }

    public function testValidateWithInvalidDownloader(): void
    {
        $constraint = new SelectDownloader();
        $value = 'invalid-downloader';

        $this->downloaderFactory->expects($this->once())
            ->method('isValidDownloader')
            ->with($value)
            ->willReturn(false);

        $this->violationBuilder->expects($this->once())
            ->method('setParameter')
            ->with('{{ value }}', $value)
            ->willReturnSelf();

        $this->violationBuilder->expects($this->once())
            ->method('addViolation');

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($constraint->message)
            ->willReturn($this->violationBuilder);

        $this->validator->validate($value, $constraint);
    }

    public function testValidateWithDifferentInvalidDownloaders(): void
    {
        $this->markTestIncomplete('This test needs to be fixed to properly count method calls.');

        $constraint = new SelectDownloader();
        $invalidValues = ['nonexistent', 'fake-dl', 'random-string'];

        $this->downloaderFactory->method('isValidDownloader')
            ->willReturn(false);

        foreach ($invalidValues as $value) {
            $this->context->expects($this->exactly(count($invalidValues)))
                ->method('buildViolation')
                ->with($constraint->message)
                ->willReturn($this->violationBuilder);

            $this->violationBuilder->expects($this->exactly(count($invalidValues)))
                ->method('setParameter')
                ->with('{{ value }}', $value)
                ->willReturnSelf();

            $this->violationBuilder->expects($this->exactly(count($invalidValues)))
                ->method('addViolation');

            $validator = new SelectDownloaderValidator($this->downloaderFactory);
            $validator->initialize($this->context);
            $validator->validate($value, $constraint);
        }
    }

    public function testValidateWithMixedValidAndInvalidValues(): void
    {
        $constraint = new SelectDownloader();

        // Test valid downloader
        $this->downloaderFactory->expects($this->once())
            ->method('isValidDownloader')
            ->with('mock')
            ->willReturn(true);

        $this->context->expects($this->never())
            ->method('buildViolation');

        $this->validator->validate('mock', $constraint);

        // Reset and test invalid downloader
        $this->setUp(); // Reset mocks

        $this->downloaderFactory->expects($this->once())
            ->method('isValidDownloader')
            ->with('invalid')
            ->willReturn(false);

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($constraint->message)
            ->willReturn($this->violationBuilder);

        $this->violationBuilder->expects($this->once())
            ->method('setParameter')
            ->with('{{ value }}', 'invalid')
            ->willReturnSelf();

        $this->violationBuilder->expects($this->once())
            ->method('addViolation');

        $this->validator->validate('invalid', $constraint);
    }

    public function testConstraintMessage(): void
    {
        $constraint = new SelectDownloader();
        $this->assertStringContainsString('{{ value }}', $constraint->message);
        $this->assertStringContainsString('downloader', $constraint->message);
    }

    public function testConstraintWithCustomMessage(): void
    {
        $customMessage = 'Custom error: "{{ value }}" is not valid.';
        $constraint = new SelectDownloader();
        $constraint->message = $customMessage;

        $this->downloaderFactory->method('isValidDownloader')
            ->willReturn(false);

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($customMessage)
            ->willReturn($this->violationBuilder);

        $this->violationBuilder->expects($this->once())
            ->method('setParameter')
            ->with('{{ value }}', 'test')
            ->willReturnSelf();

        $this->violationBuilder->expects($this->once())
            ->method('addViolation');

        $this->validator->validate('test', $constraint);
    }
}
