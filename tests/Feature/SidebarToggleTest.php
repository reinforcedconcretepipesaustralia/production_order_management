<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_toggle_renders_in_the_sidebar_footer_before_the_user_menu(): void
    {
        config(['app.env' => 'local']);

        $response = $this->actingAs(User::factory()->create())->get('/admin');

        $response->assertStatus(200);

        $html = $response->getContent();

        $this->assertStringContainsString('rcpa-sidebar-toggle', $html);

        $footerStart = strpos($html, 'fi-sidebar-footer');
        $togglePosition = strpos($html, 'rcpa-sidebar-toggle', $footerStart);
        $userMenuPosition = strpos($html, 'fi-user-menu-trigger', $footerStart);

        $this->assertNotFalse($footerStart);
        $this->assertNotFalse($togglePosition);
        $this->assertLessThan($userMenuPosition, $togglePosition);
    }

    public function test_sidebar_toggle_renders_a_visible_collapse_label(): void
    {
        config(['app.env' => 'local']);

        $response = $this->actingAs(User::factory()->create())->get('/admin');

        $response->assertStatus(200);

        $html = $response->getContent();

        $this->assertStringContainsString('rcpa-sidebar-toggle-label', $html);

        $labelPosition = strpos($html, 'rcpa-sidebar-toggle-label');
        $this->assertNotFalse($labelPosition);
        $this->assertStringContainsString('Collapse', substr($html, $labelPosition, 120));
    }
}
