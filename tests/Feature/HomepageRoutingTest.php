<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Requests\Admin\ConfigSave;
use Illuminate\Support\Facades\Validator;

class HomepageRoutingTest extends TestCase
{
    public function test_user_theme_keeps_api_root_separate_from_hidden_entry(): void
    {
        $html = view()->file(public_path('theme/xboard/dashboard.blade.php'), [
            'title' => 'Test', 'theme' => 'xboard', 'frontend_path' => 'portal_test',
            'theme_config' => ['background_url' => '', 'custom_html' => ''], 'version' => 'test',
            'description' => '', 'logo' => '',
        ])->render();
        $this->assertStringContainsString('window.routerBase = "/";', $html);
        $this->assertStringNotContainsString('window.routerBase = "/portal_test"', $html);
    }

    public function test_root_user_mode_redirects_and_respects_disabled_frontend(): void
    {
        config(['v2board.homepage_mode'=>'user','v2board.user_frontend_enable'=>1]);
        $this->get('/')->assertRedirect('/'.trim(config('v2board.frontend_user_path','user'),'/').'/');
        config(['v2board.user_frontend_enable'=>0]);$this->get('/')->assertNotFound();
    }
    public function test_closed_root_and_disabled_monitor_do_not_fall_back_to_user(): void
    {
        config(['v2board.homepage_mode'=>'closed']);$this->get('/')->assertNotFound();
        config(['v2board.homepage_mode'=>'monitor','v2board.public_status_enable'=>0]);$this->get('/')->assertNotFound();
    }
    public function test_entry_validation_checks_current_admin_path_and_partial_settings(): void
    {
        config(['v2board.secure_path'=>'secretadmin','v2board.frontend_user_path'=>'user']);
        foreach(['secretadmin','admin','api','../../foo','用户入口'] as $path){
            $r=ConfigSave::create('/','POST',['frontend_user_path'=>$path]);
            $v=Validator::make($r->all(),ConfigSave::RULES);$r->withValidator($v);
            $this->assertTrue($v->fails(),$path);
        }
        $this->assertFalse(Validator::make(['homepage_mode'=>'monitor'],ConfigSave::RULES)->fails());
        $this->assertFalse(Validator::make(['frontend_user_path'=>'portal_abc123'],ConfigSave::RULES)->fails());
    }
}
