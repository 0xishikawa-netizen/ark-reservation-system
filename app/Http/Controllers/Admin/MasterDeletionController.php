<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Masters\MasterDeletionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * マスタの削除・復元（admin 専用：can:masters.delete）。一覧画面の確認ダイアログから呼ぶ。
 */
final class MasterDeletionController extends Controller
{
    public function destroy(Request $request, string $type, int $id, MasterDeletionService $deletion): RedirectResponse
    {
        $class = $deletion->modelFor($type);
        $model = $class::query()->findOrFail($id);
        $deletion->delete($type, $model, $request->user());

        return back()->with('success', __('messages.masters.deleted', ['name' => $deletion->nameOf($model)]));
    }

    public function restore(Request $request, string $type, int $id, MasterDeletionService $deletion): RedirectResponse
    {
        $class = $deletion->modelFor($type);
        $model = $class::onlyTrashed()->findOrFail($id);
        $deletion->restore($type, $model, $request->user());

        return back()->with('success', __('messages.masters.restored', ['name' => $deletion->nameOf($model)]));
    }
}
