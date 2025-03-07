<?php

namespace App\Http\Controllers;

use App\Enums\ChannelLogoType;
use DOMDocument;
use XMLReader;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EpgGenerateController extends Controller
{
    public function __invoke(string $uuid)
    {
        // Fetch the playlist
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        }
        if (!$playlist) {
            $playlist = CustomPlaylist::where('uuid', $uuid)->firstOrFail();
        }

        // Generate a filename
        $filename = Str::slug($playlist->name) . '.xml';

        // Get all active channels
        return response()->stream(
            function () use ($playlist) {
                // Output the XML header
                echo '<?xml version="1.0" encoding="UTF-8"?>
<tv generator-info-name="Generated by m3u editor" generator-info-url="' . url('') . '">';
                echo PHP_EOL;

                // Setup the channels
                $epgChannels = [];
                $channels = $playlist->channels()
                    ->where('enabled', true)
                    ->cursor();
                foreach ($channels as $channel) {
                    // Output the <channel> tag
                    if ($channel->epgChannel) {
                        // Get the EPG channel data
                        $epgData = $channel->epgChannel;
                        $streamId = $channel->stream_id_custom ?? $channel->stream_id;

                        // Keep track of which EPGs have which channels mapped
                        // Need this to output the <programme> tags later
                        if (!array_key_exists($epgData->epg_id, $epgChannels)) {
                            $epgChannels[$epgData->epg_id] = [];
                        }
                        $epgChannels[$epgData->epg_id][] = [$epgData->channel_id => $streamId];

                        // Get the icon
                        $icon = '';
                        if ($channel->logo_type === ChannelLogoType::Epg) {
                            $icon = $epgData->icon ?? '';
                        } elseif ($channel->logo_type === ChannelLogoType::Channel) {
                            $icon = $channel->logo ?? '';
                        }

                        // Output the <channel> tag
                        $title = $channel->title_custom ?? $channel->title;
                        echo '  <channel id="' . $streamId . '">' . PHP_EOL;
                        echo '    <display-name lang="' . $epgData->lang . '">' . htmlspecialchars($title) . '</display-name>';
                        if ($icon) {
                            echo PHP_EOL . '    <icon src="' . $icon . '"/>';
                        }
                        echo PHP_EOL . '  </channel>' . PHP_EOL;
                    }
                }

                // Fetch the EPGs (channels are keyed by EPG ID)
                $epgs = Epg::whereIn('id', array_keys($epgChannels))
                    ->get();

                // Loop through the EPGs and output the <programme> tags
                foreach ($epgs as $epg) {
                    // Channel data
                    $channels = $epgChannels[$epg->id];

                    // Skip if no channels
                    if (!count($channels)) {
                        continue;
                    }

                    // Get the content
                    $filePath = null;
                    if (Storage::disk('local')->exists($epg->file_path)) {
                        $filePath = Storage::disk('local')->path($epg->file_path);
                    } elseif ($epg->uploads && Storage::disk('local')->exists($epg->uploads)) {
                        $filePath = Storage::disk('local')->path($epg->uploads);
                    }
                    if (!$filePath) {
                        // Send notification
                        $error = "Invalid EPG file. Unable to read or download an associated EPG file. Please check the URL or uploaded file and try again.";
                        Notification::make()
                            ->danger()
                            ->title("Error generating epg data for playlist \"{$playlist->name}\" using EPG \"{$epg->name}\"")
                            ->body($error)
                            ->broadcast($epg->user);
                        Notification::make()
                            ->danger()
                            ->title("Error generating epg data for playlist \"{$playlist->name}\" using EPG \"{$epg->name}\"")
                            ->body($error)
                            ->sendToDatabase($epg->user);
                        continue;
                    }

                    // Setup the reader
                    $programReader = new XMLReader();
                    $programReader->open('compress.zlib://' . $filePath);

                    // Loop through the XML data
                    while (@$programReader->read()) {
                        // Only consider XML elements and programme nodes
                        if ($programReader->nodeType == XMLReader::ELEMENT && $programReader->name === 'programme') {
                            // Get the channel id
                            $channelId = trim($programReader->getAttribute('channel'));
                            if (!$channelId) {
                                continue;
                            }

                            // EPG could be applied to multiple channels, find all matching channels
                            $filtered = array_filter($channels, fn($ch) => array_key_exists($channelId, $ch));
                            if (!count($filtered)) {
                                continue;
                            }
                            // Channel program found, output the <programme> tag
                            // First, we need to make sure the channel is correct
                            // Replace `channel="<channel_id>"` with `channel="<stream_id>"`
                            $itemDom = new DOMDocument();
                            $itemDom->loadXML($programReader->readOuterXML());

                            // Get the item element
                            $item = $itemDom->documentElement;
                            foreach ($filtered as $ch) {
                                // Modify the attribute
                                $item->setAttribute('channel', $ch[$channelId]);

                                // Output modified line
                                echo "  " . $itemDom->saveXML($item) . PHP_EOL;
                            }
                        }
                    }
                    // Close the XMLReader for this epg
                    $programReader->close();
                }

                // Close it out
                echo '</tv>';
            },
            200,
            [
                'Access-Control-Allow-Origin' => '*',
                'Content-Disposition' => "attachment; filename=$filename",
                'Content-Type' => 'application/xml'
            ]
        );
    }
}
