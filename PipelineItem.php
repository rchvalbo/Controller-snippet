<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PipelineItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'email',
        'first_name',
        'last_name',
        'title',
        'dealership',
        'website',
        'street_address',
        'county',
        'city',
        'state',
        'postal_code',
        'product',
        'budget',
        'per_car_gross',
        'sales_goal',
        'sales_average',
        'lead_source',
        'next_action',
        'last_contact_date',
        'appointment',
        'status_id',
        'market_color_id'
    ];

    protected $dates = [
        'appointment',
        'transferred_on',
    ];

    protected $appends = [
        'days_working',
    ];

    /**
     * Parses out appointment date before saving attribute to model instance.
     *
     * @param $value
     */
    public function setAppointmentAttribute($value)
    {
        if($value != null)
            $this->attributes['appointment'] = Carbon::parse($value);
    }

    public function setLastContactDateAttribute($value)
    {
        $this->attributes['last_contact_date'] = Carbon::parse($value);
        $this->number_of_contacts = $this->number_of_contacts + 1;
    }

    /**
     * User Profile of ATeam Member associated with Pipeline Item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function aTeamMemberProfile()
    {
        return $this->hasOne('App\Models\UserProfile', 'user_id', 'ateam_member_id');
    }

    /**
     * User Profile of Sales Advisor associated with Pipeline Item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function salesAdvisorProfile()
    {
        return $this->hasOne('App\Models\UserProfile', 'user_id', 'sales_advisor_id');
    }

    /**
     * Pipeline Phone Numbers associated with Pipeline Item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function phoneNumbers()
    {
        return $this->hasMany('App\Models\PipelineItemPhoneNumber');
    }

    /**
     * Activity associated with Pipeline Item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function activities()
    {
        return $this->hasMany('App\Models\PipelineItemActivity');
    }


    /**
     * Notes associated with Pipeline Item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function notes()
    {
        return $this->hasMany('App\Models\PipelineItemNote');
    }

    /**
     * Pipeline Status associated with Pipeline Item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function status()
    {
        return $this->belongsTo('App\Models\PipelineItemStatus');
    }

    /**
     * Market color associated with Pipeline Item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function marketColor()
    {
        return $this->belongsTo('App\Models\PipelineItemMarketColor');
    }

    /**
     * Transfers Pipeline Item to another user and logs transfer activity.
     * 
     *
     * @param $transferAction
     * @param $transferToId
     * @param $userId
     * @return bool
     */
    public function transfer($transferAction, $transferToId, $userId)
    {
        $oldId = null;

        switch ($transferAction) {
            case 'to-ateam':
                $oldId = $this->ateam_member_id;
                $this->ateam_member_id = $transferToId;
                break;

            case 'to-sales':
                $oldId = $this->sales_advisor_id;
                $this->sales_advisor_id = $transferToId;
                $this->status_id = 3;
                $this->number_of_contacts = 0;
                break;

            case 'admin-reassign':
                $user = User::find($transferToId);

                if ($user->userProfile->role === 'ATeam Member') {
                    $oldId = $this->ateam_member_id;
                    $this->ateam_member_id = $transferToId;
                }
                else if ($user->userProfile->role === 'Sales Advisor') {
                    $oldId = $this->sales_advisor_id;
                    $this->sales_advisor_id = $transferToId;
                }
                break;

            default:
                return false;
        }

        $this->transferred_on = Carbon::now();
        $this->save();

        return TransferActivity::create([
            'action' => $transferAction,
            'initiated_by' => $userId,
            'old_id' => $oldId,
            'new_id' => $transferToId,
        ]);
    }

    /**
     * Returns number of days working based on transferred_on date.
     *
     * @return \Carbon\Carbon || null
     */
    public function getDaysWorkingAttribute()
    {
        return $this->transferred_on
            ? $this->transferred_on->diffInDays(Carbon::now())
            : null;
    }
}
