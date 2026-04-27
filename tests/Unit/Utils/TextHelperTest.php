<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Unit\Utils;

use Jayesh\LaravelGeminiTranslator\Tests\TestCase;
use Jayesh\LaravelGeminiTranslator\Utils\TextHelper;

class TextHelperTest extends TestCase
{
    public function test_looks_machine_key_detects_laravel_keys(): void
    {
        $this->assertTrue(TextHelper::looksMachineKey('messages.hello'));
        $this->assertTrue(TextHelper::looksMachineKey('auth.failed'));
    }

    public function test_looks_machine_key_detects_snake_case(): void
    {
        $this->assertTrue(TextHelper::looksMachineKey('user_name'));
        $this->assertTrue(TextHelper::looksMachineKey('password_reset'));
    }

    public function test_looks_machine_key_detects_camel_case(): void
    {
        $this->assertTrue(TextHelper::looksMachineKey('userName'));
        $this->assertTrue(TextHelper::looksMachineKey('productId'));
    }

    public function test_looks_machine_key_human_readable_returns_false(): void
    {
        $this->assertFalse(TextHelper::looksMachineKey('Welcome to our site'));
        $this->assertFalse(TextHelper::looksMachineKey('Please enter your name'));
    }

    public function test_extract_display_text_from_namespaced_key(): void
    {
        $this->assertSame('approved', TextHelper::extractDisplayTextFromNamespacedKey('Blog::messages.comments.status.approved'));
        $this->assertSame('hello', TextHelper::extractDisplayTextFromNamespacedKey('messages.hello'));
        $this->assertSame('simple', TextHelper::extractDisplayTextFromNamespacedKey('simple'));
    }
}
