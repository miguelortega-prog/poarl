<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionNoticeRunFile extends Model
{
    protected $guarded = [];

    public function run(): BelongsTo
    {
        return $this->belongsTo(CollectionNoticeRun::class, 'collection_notice_run_id');
    }
    
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(NoticeDataSource::class, 'notice_data_source_id');
    }
    
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }    
}
