<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\JwstHelper;

class DashboardController extends Controller
{
    private function base(): string
    {
        return getenv('RUST_BASE') ?: 'http://rust_iss:3000';
    }

    private function getJson(string $url, array $qs = []): array
    {
        if ($qs) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($qs);
        }
        $raw = @file_get_contents($url);
        return $raw ? (json_decode($raw, true) ?: []) : [];
    }

    public function index()
    {
        $b = $this->base();
        $iss = $this->getJson($b . '/last');

        $cmsRow = DB::selectOne(
            "SELECT body FROM cms_pages WHERE slug = ? LIMIT 1",
            ['unsafe'] 
        );

        $cmsSafeHtml = $cmsRow ? e($cmsRow->body) : '<div class="text-muted">CMS-блок не найден</div>';

        return view('dashboard', [
            'iss' => $iss,
            'trend' => [],
            'jw_gallery' => [],
            'jw_observation_raw' => [],
            'jw_observation_summary' => [],
            'jw_observation_images' => [],
            'jw_observation_files' => [],
            'metrics' => [
                'iss_speed' => $iss['payload']['velocity'] ?? null,
                'iss_alt'   => $iss['payload']['altitude'] ?? null,
                'neo_total' => 0,
            ],
            'cmsBlockHtml' => $cmsSafeHtml, 
        ]);
    }

    public function jwstFeed(Request $r)
    {
        $src   = $r->query('source', 'jpg');
        $sfx   = trim((string)$r->query('suffix', ''));
        $prog  = trim((string)$r->query('program', ''));
        $instF = strtoupper(trim((string)$r->query('instrument', '')));
        $page  = max(1, (int)$r->query('page', 1));
        $per   = max(1, min(60, (int)$r->query('perPage', 24)));

        $jw = new JwstHelper();

        $path = 'all/type/jpg';
        if ($src === 'suffix' && $sfx !== '') {
            $path = 'all/suffix/' . ltrim($sfx, '/');
        }
        if ($src === 'program' && $prog !== '') {
            $path = 'program/id/' . rawurlencode($prog);
        }

        $resp = $jw->get($path, ['page' => $page, 'perPage' => $per]);
        $list = $resp['body'] ?? ($resp['data'] ?? (is_array($resp) ? $resp : []));

        $items = [];
        foreach ($list as $it) {
            if (!is_array($it)) continue;

            $url = null;
            $loc = $it['location'] ?? $it['url'] ?? null;
            $thumb = $it['thumbnail'] ?? null;
            foreach ([$loc, $thumb] as $u) {
                if (is_string($u) && preg_match('~\.(jpg|jpeg|png)(\?.*)?$~i', $u)) {
                    $url = $u;
                    break;
                }
            }
            if (!$url) {
                $url = \App\Support\JwstHelper::pickImageUrl($it);
            }
            if (!$url) continue;

            $instList = [];
            foreach (($it['details']['instruments'] ?? []) as $I) {
                if (is_array($I) && !empty($I['instrument'])) {
                    $instList[] = strtoupper($I['instrument']);
                }
            }
            if ($instF && $instList && !in_array($instF, $instList, true)) continue;

            $items[] = [
                'url'      => $url,
                'obs'      => (string)($it['observation_id'] ?? $it['observationId'] ?? ''),
                'program'  => (string)($it['program'] ?? ''),
                'suffix'   => (string)($it['details']['suffix'] ?? $it['suffix'] ?? ''),
                'inst'     => $instList,
                'caption'  => trim(
                    (($it['observation_id'] ?? '') ?: ($it['id'] ?? '')) .
                    ' · P' . ($it['program'] ?? '-') .
                    (($it['details']['suffix'] ?? '') ? ' · ' . $it['details']['suffix'] : '') .
                    ($instList ? ' · ' . implode('/', $instList) : '')
                ),
                'link'     => $loc ?: $url,
            ];
            if (count($items) >= $per) break;
        }

        return response()->json([
            'source' => $path,
            'count'  => count($items),
            'items'  => $items,
        ]);
    }
}