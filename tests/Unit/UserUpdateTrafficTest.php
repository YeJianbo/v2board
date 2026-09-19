<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\UserUpdate;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UserUpdateTrafficTest extends TestCase
{
    public function test_traffic_values_are_checked_before_database_write(): void
    {
        $request = new UserUpdate();
        foreach (['transfer_enable', 'u', 'd'] as $field) {
            foreach ([0, 214748364800, '10737418240000000'] as $value) {
                $this->assertFalse(Validator::make(['id' => 20, $field => $value], $request->rules())->fails());
            }
            foreach ([-1, 2.147483648E+20, '9223372036854775808'] as $value) {
                $this->assertTrue(Validator::make(['id' => 20, $field => $value], $request->rules())->fails());
            }
        }
        $this->assertFalse(Validator::make(['id' => 20, 'remarks' => 'test'], $request->rules())->fails());
    }
}
