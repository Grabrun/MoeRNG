<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Models\Image;
use App\Models\Category;
use App\Models\User;
use App\Models\ApiKey;

class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $stats = [
            'total_images' => Image::count("status = 'active'"),
            'total_categories' => Category::count(),
            'total_users' => User::count(),
            'total_api_keys' => ApiKey::count(),
        ];

        $this->render('admin/dashboard', [
            'title' => 'Dashboard',
            'stats' => $stats,
        ]);
    }
}
