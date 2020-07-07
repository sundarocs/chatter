<?php

namespace DevDojo\Chatter\Models;

use Illuminate\Database\Eloquent\Model;

class DiscussionVote extends Model
{
    protected $table = 'chatter_discussion_vote';
    public $timestamps = true;
    protected $fillable = ['discussion_id', 'user_id', 'vote', 'ip'];

    public function discussionvote()
    {
        return $this->hasMany(Models::className(DiscussionVote::class), 'discussion_id');
    }
    public function discussion()
    {
        return $this->belongsTo(Models::className(Discussion::class), 'discussion_id');
    }
    public function user()
    {
        return $this->belongsTo(config('chatter.user.namespace'));
    }
}