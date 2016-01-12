<?php

/*
	This script builds JSON playlist definitions for nginx-vod-module.
	When playlist type is set to live, it will play the MP4 files one after the other in a loop.
	When playlist type is vod, it will play each MP4 file once.
	The discontinuity parameter is true by default, set it to false only when all MP4's have 
	exactly the same encoding parameters (in the case of h264, the SPS/PPS have to be the same).
*/

// input params
$playlistType = "live";			// live / vod
$discontinuity = true;
$filePaths = array(
	"/path/to/video1.mp4",
	"/path/to/video2.mp4",
	"/path/to/video3.mp4",
	);
$ffprobeBin = '/path/to/ffprobe.sh';
$timeMargin = 10000;		// a safety margin to compensate for clock differences

// nginx conf params
$segmentDuration = 10000;
$segmentCount = 10;

function getSourceClip($filePath)
{
	return array(
		"type" => "source",
		"path" => $filePath
	);
}

function getDurationMillis($filePath)
{
	global $ffprobeBin;

	// try to fetch from cache
	$cacheKey = "duration-{$filePath}";
	$duration = apc_fetch($cacheKey);
	if ($duration)
	{
		return $duration;
	}
	
	// execute ffprobe
	$commandLine = "{$ffprobeBin} -i {$filePath} -show_streams -print_format json -v quiet";
	$output = null;
	exec($commandLine, $output);
	
	// parse the result - take the shortest duration
	$ffmpegResult = json_decode(implode("\n", $output));
	$duration = null;
	foreach ($ffmpegResult->streams as $stream)
	{
		$curDuration = floor(floatval($stream->duration) * 1000);
		if (is_null($duration) || $curDuration < $duration)
		{
			$duration = $curDuration;
		}
	}
	
	// store to cache
	apc_store($cacheKey, $duration);
	
	return $duration;
}

$durations = array_map('getDurationMillis', $filePaths);

$result = array();

if ($playlistType == "live")
{
	// find the duration of each cycle
	$cycleDuration = array_sum($durations);
	
	// if the cycle is too small to cover the DVR window, duplicate it
	$dvrWindowSize = $segmentDuration * $segmentCount;
	while ($cycleDuration <= $dvrWindowSize + $timeMargin)
	{
		$filePaths = array_merge($filePaths, $filePaths);
		$durations = array_merge($durations, $durations);
		$cycleDuration *= 2;
	}
	
	// start the playlist from now() - <dvr window size>
	$currentTime = time() * 1000 - $timeMargin - $dvrWindowSize;
	
	// set the first clip time to now() rounded down to cycle duration
	$result["firstClipTime"] = floor($currentTime / $cycleDuration) * $cycleDuration;
	
	// get the reference time (the first clip time of the first run)
	$referenceTime = apc_fetch('reference_time');
	if (!$referenceTime)
	{
		$referenceTime = $result["firstClipTime"];
		apc_store('reference_time', $referenceTime);
	}

	if ($discontinuity)
	{
		// find the total number of segments in each cycle
		$totalSegmentCount = 0;
		foreach ($durations as $duration)
		{
			$totalSegmentCount += ceil($duration / $segmentDuration);
		}

		// find the initial clip index and initial segment index
		$cycleIndex = floor(($currentTime - $referenceTime) / $cycleDuration);
		$result["initialClipIndex"] = $cycleIndex * count($durations) + 1;
		$result["initialSegmentIndex"] = $cycleIndex * $totalSegmentCount + 1;
	}
	else
	{
		$result["segmentBaseTime"] = $referenceTime;
	}

	// duplicate the clips, this is required so that we won't run out of segments 
	// close to the end of a cycle
	$filePaths = array_merge($filePaths, $filePaths);
	$durations = array_merge($durations, $durations);
}

// generate the result object
$result = array_merge($result, array(
	"discontinuity" => $discontinuity,
	"playlistType" => $playlistType,
	"durations" => $durations,
	"sequences" => array(
		array(
			"clips" => array_map('getSourceClip', $filePaths)
		)
	)
));

echo json_encode($result);