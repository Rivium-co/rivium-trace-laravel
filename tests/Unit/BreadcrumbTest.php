<?php

namespace RiviumTrace\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RiviumTrace\Laravel\Models\Breadcrumb;
use RiviumTrace\Laravel\Models\BreadcrumbManager;

class BreadcrumbTest extends TestCase
{
    public function test_creates_http_breadcrumb(): void
    {
        $crumb = Breadcrumb::http('GET', '/api/users', 200, 45.5);

        $this->assertEquals('GET /api/users', $crumb->message);
        $this->assertEquals('http', $crumb->category);
        $this->assertEquals('info', $crumb->level);
        $this->assertEquals(200, $crumb->data['status_code']);
        $this->assertEquals(45.5, $crumb->data['duration_ms']);
    }

    public function test_http_breadcrumb_error_level_on_4xx(): void
    {
        $crumb = Breadcrumb::http('POST', '/api/users', 404);

        $this->assertEquals('error', $crumb->level);
    }

    public function test_creates_database_breadcrumb(): void
    {
        $crumb = Breadcrumb::database('SELECT * FROM users WHERE id = 1', 12.5);

        $this->assertEquals('database', $crumb->category);
        $this->assertEquals('info', $crumb->level);
        $this->assertStringContainsString('SELECT * FROM users', $crumb->message);
        $this->assertEquals(12.5, $crumb->data['duration_ms']);
    }

    public function test_database_breadcrumb_truncates_long_queries(): void
    {
        $longQuery = 'SELECT ' . str_repeat('a', 600) . ' FROM users';
        $crumb = Breadcrumb::database($longQuery, 10);

        $this->assertLessThanOrEqual(500, mb_strlen($crumb->data['query']));
    }

    public function test_creates_user_breadcrumb(): void
    {
        $crumb = Breadcrumb::user('clicked_button', ['button' => 'submit']);

        $this->assertEquals('User action: clicked_button', $crumb->message);
        $this->assertEquals('user', $crumb->category);
        $this->assertEquals(['button' => 'submit'], $crumb->data);
    }

    public function test_creates_custom_breadcrumb(): void
    {
        $crumb = Breadcrumb::custom('Custom event', 'custom', ['key' => 'value']);

        $this->assertEquals('Custom event', $crumb->message);
        $this->assertEquals('custom', $crumb->category);
    }

    public function test_creates_navigation_breadcrumb(): void
    {
        $crumb = Breadcrumb::navigation('/home', '/about', 'GET');

        $this->assertEquals('GET /about', $crumb->message);
        $this->assertEquals('navigation', $crumb->category);
    }

    public function test_to_array(): void
    {
        $crumb = Breadcrumb::http('GET', '/test', 200, 10);
        $array = $crumb->toArray();

        $this->assertArrayHasKey('timestamp', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('category', $array);
        $this->assertArrayHasKey('level', $array);
        $this->assertArrayHasKey('data', $array);
    }

    // BreadcrumbManager Tests

    public function test_manager_adds_breadcrumbs(): void
    {
        $manager = new BreadcrumbManager(50);
        $manager->add(Breadcrumb::http('GET', '/test'));

        $this->assertEquals(1, $manager->count());
    }

    public function test_manager_accepts_arrays(): void
    {
        $manager = new BreadcrumbManager(50);
        $manager->add(['message' => 'test', 'category' => 'manual']);

        $this->assertEquals(1, $manager->count());
    }

    public function test_manager_enforces_max_limit(): void
    {
        $manager = new BreadcrumbManager(3);

        for ($i = 0; $i < 5; $i++) {
            $manager->add(Breadcrumb::custom("Event {$i}", 'test'));
        }

        $this->assertEquals(3, $manager->count());

        $recent = $manager->getRecent(3);
        $this->assertEquals('Event 2', $recent[0]['message']);
        $this->assertEquals('Event 4', $recent[2]['message']);
    }

    public function test_manager_get_recent(): void
    {
        $manager = new BreadcrumbManager(50);

        for ($i = 0; $i < 10; $i++) {
            $manager->add(Breadcrumb::custom("Event {$i}", 'test'));
        }

        $recent = $manager->getRecent(3);
        $this->assertCount(3, $recent);
        $this->assertEquals('Event 7', $recent[0]['message']);
    }

    public function test_manager_clear(): void
    {
        $manager = new BreadcrumbManager(50);
        $manager->add(Breadcrumb::http('GET', '/test'));
        $manager->clear();

        $this->assertEquals(0, $manager->count());
    }

    public function test_manager_zero_max_does_nothing(): void
    {
        $manager = new BreadcrumbManager(0);
        $manager->add(Breadcrumb::http('GET', '/test'));

        $this->assertEquals(0, $manager->count());
    }
}
