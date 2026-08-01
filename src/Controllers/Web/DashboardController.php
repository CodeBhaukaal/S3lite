<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Models\FileRecord;
use App\Models\Notification;
use App\Services\Auth;
use App\Services\StatsService;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->userId();

        $recent = FileRecord::search(['user_id' => $userId], 1, 8, 'created_at', 'desc');

        return $this->view('app.dashboard', [
            'stats'      => StatsService::forUser($userId),
            'timeline'   => StatsService::timeline(14, $userId),
            'byType'     => StatsService::storageByType($userId),
            'recent'     => $recent['data'],
            'topFiles'   => StatsService::topFiles(5, $userId),
            'activity'   => StatsService::recentActivity(10, $userId),
            'isAdmin'    => Auth::isAdmin(),
            'globalStats' => Auth::isAdmin() ? StatsService::global() : null,
        ]);
    }

    public function readNotifications(Request $request): Response
    {
        Notification::markAllRead($this->userId());

        return $this->back($request);
    }

    /** Live counters for the dashboard's auto-refresh. */
    public function stats(Request $request): Response
    {
        $userId = $this->userId();

        return $this->json([
            'user'   => StatsService::forUser($userId),
            'global' => Auth::isAdmin() ? StatsService::global() : null,
        ]);
    }
}
