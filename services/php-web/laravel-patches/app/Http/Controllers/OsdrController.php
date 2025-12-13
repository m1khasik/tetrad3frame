<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class OsdrController extends Controller
{
    public function index(Request $request)
    {
        $limit = max(1, min(100, (int)$request->query('limit', 20))); // валидация: от 1 до 100
        $page = max(1, (int)$request->query('page', 1)); // текущая страница
        $base  = getenv('RUST_BASE') ?: 'http://rust_iss:3000';

        // Получаем больше данных для пагинации (или все, если API поддерживает)
        $totalLimit = $limit * $page; // получаем достаточно данных для текущей страницы
        $json  = @file_get_contents($base.'/osdr/list?limit='.$totalLimit);
        $data  = $json ? json_decode($json, true) : ['items' => []];
        $items = $data['items'] ?? [];

        $items = $this->flattenOsdr($items); // разворачиваем данные
        
        // Пагинация на стороне PHP (если API не поддерживает offset)
        $totalItems = count($items);
        $offset = ($page - 1) * $limit;
        $items = array_slice($items, $offset, $limit);
        $totalPages = max(1, (int)ceil($totalItems / $limit)); // минимум 1 страница

        return view('osdr', [
            'items' => $items,
            'src'   => $base.'/osdr/list?limit='.$totalLimit,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'limit' => $limit,
            'totalItems' => $totalItems,
        ]);
    }

    /** Преобразует данные вида {"OSD-1": {...}, "OSD-2": {...}} в плоский список */
    private function flattenOsdr(array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            $raw = $row['raw'] ?? [];
            if (is_array($raw) && $this->looksOsdrDict($raw)) {
                foreach ($raw as $k => $v) {
                    if (!is_array($v)) continue;
                    $rest = $v['REST_URL'] ?? $v['rest_url'] ?? $v['rest'] ?? null;
                    $title = $v['title'] ?? $v['name'] ?? null;
                    if (!$title && is_string($rest)) {
                        // запасной вариант: последний сегмент URL как подпись
                        $title = basename(rtrim($rest, '/'));
                    }
                    $out[] = [
                        'id'          => $row['id'],
                        'dataset_id'  => $k,
                        'title'       => $title,
                        'status'      => $row['status'] ?? null,
                        'updated_at'  => $row['updated_at'] ?? null,
                        'inserted_at' => $row['inserted_at'] ?? null,
                        'rest_url'    => $rest,
                        'raw'         => $v,
                    ];
                }
            } else {
                // обычная строка — просто прокинем REST_URL если найдётся
                $row['rest_url'] = is_array($raw) ? ($raw['REST_URL'] ?? $raw['rest_url'] ?? null) : null;
                $out[] = $row;
            }
        }
        return $out;
    }

    private function looksOsdrDict(array $raw): bool
    {
        // словарь ключей "OSD-xxx" ИЛИ значения содержат REST_URL
        foreach ($raw as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'OSD-')) return true;
            if (is_array($v) && (isset($v['REST_URL']) || isset($v['rest_url']))) return true;
        }
        return false;
    }
}