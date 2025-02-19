<?php

namespace App\Jobs;

use App\Enums\PlaylistStatus;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Job;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessM3uImportComplete implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $userId,
        public int $playlistId,
        public array $groups,
        public string $batchNo,
        public Carbon $start,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Calculate the time taken to complete the import
        $completedIn = $this->start->diffInSeconds(now());
        $completedInRounded = round($completedIn, 2);

        $user = User::find($this->userId);
        $playlist = $user->playlists()->find($this->playlistId);

        // Send notification
        Notification::make()
            ->success()
            ->title('Playlist Synced')
            ->body("\"{$playlist->name}\" has been synced successfully.")
            ->broadcast($playlist->user);
        Notification::make()
            ->success()
            ->title('Playlist Synced')
            ->body("\"{$playlist->name}\" has been synced successfully. Import completed in {$completedInRounded} seconds.")
            ->sendToDatabase($playlist->user);

        // Clear out invalid groups (if any)
        Group::where([
            ['custom', false],
            ['playlist_id', $playlist->id],
            ['import_batch_no', '!=', $this->batchNo],
        ])->delete();

        // Clear out invalid channels (if any)
        Channel::where([
            ['playlist_id', $playlist->id],
            ['import_batch_no', '!=', $this->batchNo],
        ])->delete();

        // Clear out the jobs
        Job::where(['batch_no', $this->batchNo])->delete();

        // Update the import preferences
        if ($playlist->import_prefs['preprocess'] ?? false) {
            $importPrefs = [
                ...$playlist->import_prefs ?? [],

                // Make sure there's no selected groups that are no longer in the available groups
                'selected_groups' => array_intersect($playlist->import_prefs['selected_groups'] ?? [], $this->groups),
            ];
        } else {
            // no changes to import prefs
            $importPrefs = $playlist->import_prefs;
        }

        // Update the playlist
        $playlist->update([
            'status' => PlaylistStatus::Completed,
            'channels' => 0, // not using...
            'synced' => now(),
            'errors' => null,
            'sync_time' => $completedIn,
            'progress' => 100,
            'processing' => false,
            'import_prefs' => $importPrefs,
            'groups' => $this->groups,
        ]);
    }
}
