<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Booth\CreateBooth;
use App\Actions\Booth\ToggleBoothActive;
use App\Actions\Booth\UpdateBooth;
use App\Domain\Masters\MasterDeletionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBoothRequest;
use App\Http\Requests\Admin\UpdateBoothRequest;
use App\Models\Booth;
use App\Queries\BoothListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BoothController extends Controller
{
    public function index(Request $request, BoothListQuery $query): Response
    {
        $searchInput = $request->query('search');
        $search = is_string($searchInput) ? trim($searchInput) : '';
        $booths = $query->get($search);

        return Inertia::render('Admin/Booths/Index', [
            // 削除済み（復元用）は管理者（masters.delete）にだけ渡す。
            'trashed' => request()->user()?->can('masters.delete') ? app(MasterDeletionService::class)->trashed('booths') : [],
            'booths' => $booths->map(fn (Booth $booth): array => [
                'id' => $booth->id,
                'name' => $booth->name,
                'sort_order' => $booth->sort_order,
                'is_active' => $booth->is_active,
            ])->values(),
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Booths/Create');
    }

    public function store(
        StoreBoothRequest $request,
        CreateBooth $createBooth,
    ): RedirectResponse {
        $createBooth->execute($request->validated(), $request->user());

        return redirect()->route('admin.booths.index')
            ->with('success', __('messages.booth.created'));
    }

    public function edit(Booth $booth): Response
    {
        return Inertia::render('Admin/Booths/Edit', [
            'booth' => [
                'id' => $booth->id,
                'name' => $booth->name,
                'sort_order' => $booth->sort_order,
                'is_active' => $booth->is_active,
            ],
        ]);
    }

    public function update(
        UpdateBoothRequest $request,
        Booth $booth,
        UpdateBooth $updateBooth,
    ): RedirectResponse {
        $updateBooth->execute($booth, $request->validated(), $request->user());

        return redirect()->route('admin.booths.index')
            ->with('success', __('messages.booth.updated'));
    }

    public function setActive(
        Request $request,
        Booth $booth,
        ToggleBoothActive $toggleBoothActive,
    ): RedirectResponse {
        $validated = $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $toggleBoothActive->execute(
            $booth,
            (bool) $validated['active'],
            $request->user(),
        );

        return back()->with(
            'success',
            $validated['active'] ? __('messages.booth.activated') : __('messages.booth.deactivated'),
        );
    }
}
