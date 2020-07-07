<?php

namespace DevDojo\Chatter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use DB;
use Auth;
use Carbon\Carbon;
use Event as EventListener;

class Discussion extends Model
{
    protected $table = 'chatter_discussion';
    public $timestamps = true;
    protected $fillable = ['title', 'chatter_category_id', 'user_id', 'slug', 'color', 'status', 'start_date', 'end_date'];


    public static function boot() 
    {
        parent::boot();

        static::created(function($discussion) {
            $data['content']      = $discussion->title;
            $data['content_id']   = $discussion->id;
            $data['content_type'] = 'discussion_title';
            EventListener::fire('content.created', ['content' => $data]);
        });

        static::updated(function($discussion) {
            $data['content']      = $discussion->title;
            $data['content_id']   = $discussion->id;
            $data['content_type'] = 'discussion_title';
            EventListener::fire('content.updated', ['content' => $data]);
        });

        static::deleted(function($discussion) {
            $data['content']      = $discussion->title;
            $data['content_id']   = $discussion->id;
            $data['content_type'] = 'discussion_title';
            EventListener::fire('content.deleted', ['content' => $data]);
        });
    }

    public function user()
    {
        return $this->belongsTo(config('chatter.user.namespace'));
    }

    public function category()
    {
        return $this->belongsTo(Models::className(Category::class), 'chatter_category_id');
    }

    public function posts()
    {
        return $this->hasMany(Models::className(Post::class), 'chatter_discussion_id');
    }

    public function discussionvote()
    {
        return $this->hasMany(Models::className(DiscussionVote::class), 'discussion_id');
    }

    public function post()
    {
        return $this->hasMany(Models::className(Post::class), 'chatter_discussion_id')->orderBy('created_at', 'ASC');
    }

    public function postsCount()
    {
        $reportedId = array();
        if (!Auth::guest()) {
            $reportedId = DB::table('chatter_discussion_reports')->where('reporter_id', '=', Auth::user()->id)->where('comment_id', '!=', NULL)->pluck('comment_id');
            $replyIds = Models::post()->whereIn('parent_id', $reportedId)->pluck('id')->toArray();
            $reportedId = array_merge($replyIds, convertToArray($reportedId));
        }
        return $this->posts()->whereNotIn('id', $reportedId)
        ->selectRaw('chatter_discussion_id, count(*)-1 as total')->whereNull('deleted_at')
        ->groupBy('chatter_discussion_id');
    }

    public function users()
    {
        return $this->belongsToMany(config('chatter.user.namespace'), 'chatter_user_discussion', 'discussion_id', 'user_id');
    }

    // Create unique slug for discussion
    public static function getUniqueSlug($slug, $id = '')
    {
        if ($id) {
            $discussion_exists = Models::discussion()->where('slug', '=', $slug)->where('id', '!=', $id)->first();
        } else {
            $discussion_exists = Models::discussion()->where('slug', '=', $slug)->first();
        }

        $new_slug = $slug;
        if ($discussion_exists) {
            while (isset($discussion_exists->id)) {
                $new_slug = $slug . '-' . rand(11111, 99999);
                $discussion_exists = Models::discussion()->where('slug', '=', $new_slug)->first();
            }
        }

        return $new_slug;
    }

    // Create slug for discussion
    public static function generateSlug($title, $id = '')
    {
        $slug = str_slug($title, '-');

        if (empty($slug)) {
            $slug = rand(11111, 99999);
        }

        return self::getUniqueSlug($slug);
    }

    // Get discussion URL
    public static function getUrl($id = '', $full_path = true)
    {
        $url = '/';
        if ($id > 0) {
            $discussion = self::find($id);
            $url .= Config::get('chatter.routes.home') . '/' . Config::get('chatter.routes.discussion') . '/' . $discussion->category->slug . '/' . $discussion->slug;
        }
        if ($full_path === true) {
            return url($url);
        } else {
            return $url;
        }
    }

    public static function addReportAbuse($request)
    {
        $id = $request->report_id;
        $reason = $request->reason;
        $reason_content = ($reason == 'other' ? $request->reason_content : NULL);
        $user_id = Auth::user()->id;

        if ($id && $reason && $user_id) {
            $reported_data = Discussion::where('id', '=', $id)->first();
            if ($reported_data) {
                $report = DB::table('chatter_discussion_reports')->where('discussion_id', '=', $id)->where('reporter_id', '=', $user_id)->first();
                if (!$report) {
                    $reported = DB::table('chatter_discussion_reports')->insert([
                        'discussion_id' => $id, 
                        'reporter_id' => $user_id, 
                        'reason' => $reason, 
                        'reason_content' => $reason_content, 
                        'status' => 'pending', 
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now()
                    ]);
                } else {
                    $reported = DB::table('chatter_discussion_reports')->where('id', $report->id)->update([
                        'reason' => $reason, 
                        'reason_content' => $reason_content, 
                        'status' => 'pending', 
                        'updated_at' => Carbon::now()
                    ]);
                }    
                return $reported;
            }
        }
        return false;
    }
}
