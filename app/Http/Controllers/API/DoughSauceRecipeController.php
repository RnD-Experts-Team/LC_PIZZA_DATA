<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\DoughSauce\StoreRecipeRequest;
use App\Http\Requests\DoughSauce\UpdateRecipeRequest;
use App\Models\Aggregation\DsIngredient;
use App\Models\Aggregation\DsMenuItem;
use App\Models\Aggregation\DsRecipe;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Maintaining the bill of materials.
 *
 * Recipes are not static — a new menu item every couple of months, a seasonal
 * promotion, a portion size that changes. Every one of those, left unrecorded,
 * becomes an item selling with no recipe, which is exactly the hole this module
 * exists to close. So there has to be a way to add one without a deploy.
 *
 * The screen lives in AuditApp: when the specialist sees "3 items sold with no
 * recipe" there, the fix is a button on the same card rather than a different
 * system. These endpoints are what that button calls.
 */
class DoughSauceRecipeController extends Controller
{
    /** The three tracked ingredients, for building the form. */
    public function ingredients(): JsonResponse
    {
        return response()->json([
            'ingredients' => DsIngredient::query()
                ->active()
                ->orderBy('sort_order')
                ->get(['key', 'name', 'unit', 'divisor', 'inventory_ref']),
        ]);
    }

    /**
     * Recipes in force, optionally for one item and/or as of one date.
     *
     * `as_of` defaults to today: without it the list would show every historical
     * row and read as though one item had three conflicting recipes.
     */
    public function index(Request $request): JsonResponse
    {
        // `all` is normalised before validating for the same reason as
        // include_refunded on the plan request: ?all=true arrives as a string,
        // and the `boolean` rule does not accept the spelled-out word.
        if ($request->has('all')) {
            $request->merge([
                'all' => filter_var($request->input('all'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        $data = $request->validate([
            'item_id' => 'sometimes|string|max:20',
            'as_of'   => 'sometimes|date_format:Y-m-d',
            'all'     => 'sometimes|boolean',
        ]);

        $query = DsRecipe::query()->with(['menuItem', 'ingredient']);

        if (! empty($data['item_id'])) {
            $query->whereHas('menuItem', fn ($q) => $q->forItem($data['item_id']));
        }

        if (! filter_var($data['all'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->effectiveOn($data['as_of'] ?? now()->toDateString());
        }

        $rows = $query->get()->map(fn (DsRecipe $r) => [
            'id'                => $r->id,
            'item_id'           => $r->menuItem?->item_id,
            'menu_item_name'    => $r->menuItem?->menu_item_name,
            'menu_item_account' => $r->menuItem?->menu_item_account,
            'ingredient_key'    => $r->ingredient?->key,
            'qty'               => (float) $r->qty,
            'effective_from'    => $r->effective_from?->toDateString(),
            'effective_to'      => $r->effective_to?->toDateString(),
        ]);

        return response()->json([
            'as_of'   => $data['as_of'] ?? now()->toDateString(),
            'recipes' => $rows,
            'count'   => $rows->count(),
        ]);
    }

    /**
     * Add a recipe — creating the menu item if this is the first time we have seen it.
     *
     * That fallback is the point: the items that need a recipe most are the ones
     * nobody has catalogued yet.
     */
    public function store(StoreRecipeRequest $request): JsonResponse
    {
        $data          = $request->validated();
        $effectiveFrom = $data['effective_from'] ?? now()->toDateString();

        $recipes = DB::connection('aggregation')->transaction(function () use ($data, $effectiveFrom, $request) {
            $item = DsMenuItem::updateOrCreate(
                ['item_id' => $data['item_id']],
                [
                    'menu_item_name'    => $data['menu_item_name'],
                    'menu_item_account' => $data['menu_item_account'],
                    'active'            => true,
                ],
            );

            $ingredients = DsIngredient::query()
                ->whereIn('key', array_column($data['lines'], 'ingredient_key'))
                ->get()
                ->keyBy('key');

            $created = [];

            foreach ($data['lines'] as $line) {
                $created[] = DsRecipe::updateOrCreate(
                    [
                        'ds_menu_item_id'  => $item->id,
                        'ds_ingredient_id' => $ingredients[$line['ingredient_key']]->id,
                        'effective_from'   => $effectiveFrom,
                    ],
                    [
                        'qty'        => $line['qty'],
                        'created_by' => $this->actorId($request),
                    ],
                );
            }

            return $created;
        });

        return response()->json([
            'item_id'        => $data['item_id'],
            'effective_from' => $effectiveFrom,
            'recipes'        => array_map(fn (DsRecipe $r) => [
                'id'  => $r->id,
                'qty' => (float) $r->qty,
            ], $recipes),
        ], 201);
    }

    /**
     * Change a quantity — by closing the current row and opening a new one.
     *
     * Never an in-place update. A store manager can open a past date, and that day
     * has to compute with the recipe that was in force then. Overwriting would
     * rewrite every plan and every score ever built on the old number, silently.
     */
    public function update(UpdateRecipeRequest $request, DsRecipe $recipe): JsonResponse
    {
        $data          = $request->validated();
        $effectiveFrom = Carbon::parse($data['effective_from'] ?? now()->toDateString());

        abort_if(
            $recipe->effective_to !== null,
            422,
            'This recipe row is already closed. Edit the row that is currently in force.'
        );

        abort_if(
            $effectiveFrom->lte($recipe->effective_from),
            422,
            'effective_from must be after the current row started ('
            . $recipe->effective_from->toDateString() . ').'
        );

        $new = DB::connection('aggregation')->transaction(function () use ($recipe, $data, $effectiveFrom, $request) {
            $recipe->update(['effective_to' => $effectiveFrom->copy()->subDay()->toDateString()]);

            return DsRecipe::create([
                'ds_menu_item_id'  => $recipe->ds_menu_item_id,
                'ds_ingredient_id' => $recipe->ds_ingredient_id,
                'qty'              => $data['qty'],
                'effective_from'   => $effectiveFrom->toDateString(),
                'created_by'       => $this->actorId($request),
            ]);
        });

        return response()->json([
            'closed' => [
                'id'           => $recipe->id,
                'qty'          => (float) $recipe->qty,
                'effective_to' => $recipe->fresh()->effective_to?->toDateString(),
            ],
            'opened' => [
                'id'             => $new->id,
                'qty'            => (float) $new->qty,
                'effective_from' => $new->effective_from->toDateString(),
            ],
        ]);
    }

    /**
     * Retire a recipe — a soft close, not a delete.
     *
     * Deleting the row would change what past days compute to. Closing it leaves
     * history intact and simply stops it applying from tomorrow.
     */
    public function destroy(Request $request, DsRecipe $recipe): JsonResponse
    {
        abort_if($recipe->effective_to !== null, 422, 'This recipe row is already closed.');

        $recipe->update(['effective_to' => now()->subDay()->toDateString()]);

        return response()->json([
            'closed'       => true,
            'id'           => $recipe->id,
            'effective_to' => $recipe->fresh()->effective_to?->toDateString(),
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Who is making the change.
     *
     * auth()->id() is null for employee-subject tokens: the middleware looks the
     * employee up and sets a request attribute, but deliberately does not call
     * Auth::login() for them. Reading only auth()->id() would record an anonymous
     * edit for half the people who can make one.
     */
    private function actorId(Request $request): ?int
    {
        $employeeId = $request->attributes->get('authz_employee_id');

        return $employeeId ? (int) $employeeId : auth()->id();
    }
}
