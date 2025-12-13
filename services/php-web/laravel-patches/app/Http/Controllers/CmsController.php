<?php
namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;

class CmsController extends Controller {
  public function page(string $slug) {
    $row = DB::selectOne("SELECT title, body FROM cms_pages WHERE slug = ?", [$slug]);
    if (!$row) abort(404);
    return response()->view('cms.page', ['title' => $row->title, 'html' => $row->body]);
  }

  /**
   * Получить CMS-блок по slug (для использования в других контроллерах)
   */
  public static function getBlock(string $slug) {
    try {
      return DB::selectOne("SELECT title, body FROM cms_pages WHERE slug = ? LIMIT 1", [$slug]);
    } catch (\Throwable $e) {
      return null;
    }
  }
}