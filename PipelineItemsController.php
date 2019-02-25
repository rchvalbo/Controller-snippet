<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\PipelineItem;
use App\Models\PipelineItemStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PipelineItemsController extends Controller
{
    /**
     * Returns all Pipeline Items associated with user.
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = $request->user()
                         ->pipelineItems()
                         ->orderBy('last_contact_date', 'asc')
                         ->with([
                             'phoneNumbers',
                             'notes.userProfile',
                             'status',
                             'marketColor',
                             'aTeamMemberProfile',
                             'salesAdvisorProfile',
                         ]);

        if ($request->filled('closed_month')) {
            $query->whereIn('status_id', PipelineItemStatus::getClosedStatuses())
                  ->whereDate('updated_at', '>', Carbon::now()->subDays(90));
        }

        $pipelineItems = $query->get();

        return response()->json([
            'success' => true,
            'data' => ['pipeline_items' => $pipelineItems],
        ]);
    }

    /**
     * Return specific Pipeline Item for authenticated user.
     * @param Request $request
     * @param $item
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $item)
    {
        $pipelineItem = $request->user()
                                ->pipelineItems()
                                ->where('id', $item)
                                ->with(['phoneNumbers', 'notes', 'status', 'marketColor'])
                                ->get();

        return response()->json([
            'success' => true,
            'data' => $pipelineItem,
        ]);
    }

    /**
     * Store Pipeline Item in the db for authenticated user.
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            // 'email' => 'required|email',
            'first_name' => 'required|string|max:140',
            // 'last_name' => 'required|string|max:140',
            'dealership' => 'required|string|max:140',
            'website' => 'string|max:240',
            // 'county' => 'required|string|max:140',
            // 'state' => 'required|string|max:70',
            // 'product' => 'required|string',
            // 'budget' => 'required|numeric|max:99999999',
            'status_id' => 'required|exists:pipeline_item_statuses,id',
            // 'market_color' => 'required|exists:pipeline_item_market_colors,id',
        ]);

        $user = $request->user();
        $role = $user->role_column_name;

        $newItem = new PipelineItem();
        $newItem[$role] = $user->id;
        $newItem->email = $request->email;
        $newItem->first_name = $request->first_name;
        $newItem->last_name = $request->last_name;
        $newItem->title = $request->title;
        $newItem->dealership = $request->dealership;
        $newItem->website = $request->website;
        $newItem->street_address = $request->street_address;
        $newItem->county = $request->county;
        $newItem->city = $request->city;
        $newItem->state = $request->state;
        $newItem->postal_code = $request->postal_code;
        $newItem->product = $request->product;
        $newItem->budget = $request->budget;
        $newItem->per_car_gross = $request->per_car_gross;
        $newItem->sales_goal = $request->sales_goal;
        $newItem->sales_average = $request->sales_average;
        $newItem->number_of_contacts = 0;
        $newItem->lead_source = $request->input('lead_source', "Created by $user->profile");

        if ($request->filled('next_action')) {
            $newItem->next_action = $request->next_action;
            $newItem->next_action_date = Carbon::parse($request->next_action_date);
        }

        if ($request->filled('appointment'))
            $newItem->appointment = Carbon::parse($request->appointment);

        $newItem->status_id = $request->status_id;
        $newItem->market_color_id = $request->market_color;
        $newItem->save();

        // Attach phone numbers to new Pipeline Item.
        if ($request->filled('phone_numbers')) {
            $newNumbers = collect();

            foreach ($request->phone_numbers as $phone_number) {
                $newNumbers->push([
                    'type' => array_get($phone_number, 'type', 'Office'),
                    'number' => $phone_number['number'],
                ]);
            }

            $newItem->phoneNumbers()->createMany($newNumbers->all());
        }

        if ($request->filled('note'))
            $newItem->notes()->create(['body' => $request->note, 'user_id' => $request->user()->id]);

        return redirect("/pipeline-items/$newItem->id");
    }

    /**
     * Update specific Pipeline Item for authenticated user.
     * @param $item
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update($item, Request $request)
    {
        //Commented out the validation while in developement.
        $request->validate([
            // 'email' => 'email',
            // 'first_name' => 'string|max:140',
            // 'last_name' => 'string|max:140',
            // 'dealership' => 'string|max:140',
            // 'website' => 'string|max:240',
            // 'county' => 'string|max:140',
            // 'state' => 'string|max:70',
            // 'product' => 'string',
            // 'appointment' => 'date',
            // 'budget' => 'numeric|max:99999999',
            // 'status_id' => 'exists:pipeline_item_statuses,id',
            // 'market_color' => 'exists:pipeline_item_market_colors,id',
        ]);

        if ($request->user()->hasRole('Admin'))
            $pipelineItem = PipelineItem::find($item);
        else
            $pipelineItem = $request->user()->pipelineItems()->where('id', $item)->first();

        if(!$pipelineItem)
            return response()->json([
                'success' => false,
                'message' => 'Pipeline Item not found.',
            ], 404);
            
        $pipelineItem->update($request->all());

        // TODO: Add ability to save multiple phone numbers.
        if ($request->filled('phone_numbers')) {
            $newNumbers = collect($request->phone_numbers);

            $pipelineItem->phoneNumbers()->delete();

            $pipelineItem->phoneNumbers()->createMany($newNumbers->all());
        }

        return response()->json([
            'success' => true,
            'data' => $pipelineItem->id,
        ], 200);
    }

    /**
     * Fetch Pipeline Items based on appointment date.
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function appointments(Request $request)
    {
        $request->validate([
            'date' => 'date_format:m/d/Y',
        ]);

        $date = $request->filled('date')
            ? Carbon::parse($request->input('date'))
            : Carbon::now()->setTimezone('America/New_York');

        $items = PipelineItem::whereDate('appointment', $date)
                             ->with([
                                 'phoneNumbers',
                                 'notes',
                                 'status',
                                 'marketColor',
                                 'aTeamMemberProfile',
                                 'salesAdvisorProfile',
                              ])
                             ->orderBy('appointment')
                             ->get();

        return response()->json(['data' => $items], 200);
    }

    /**
     * Transfer Pipeline Items.
     * @param $item
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function transferItem($item, Request $request)
    {
        $request->validate([
            'transferAction' => 'required',
            'transferToId' => 'required|exists:users,id',
        ]);

        $user = $request->user();
        $transferToId = $request->transferToId;

        if ($request->user()->hasRole('Admin')) {
            $pipelineItem = PipelineItem::find($item);
            $transferAction = 'admin-reassign';
        }
        else {
            $pipelineItem = $user->pipelineItems()->where('id', $item)->first();
            $transferAction = $request->transferAction;
        }

        $result = $pipelineItem->transfer($transferAction, $transferToId, $user->id);

        $response = $result
            ? ['data' => ['success' => true], 'code' => 200]
            : ['data' => ['success' => false], 'code' => 402];

        return response()->json($response['data'], $response['code']);
    }
}
