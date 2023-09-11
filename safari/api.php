<?php
$url = filter_input(INPUT_GET, 'url', FILTER_SANITIZE_URL);
$nextSong = filter_input(INPUT_GET, 'next', FILTER_VALIDATE_BOOLEAN);
$historic = filter_input(INPUT_GET, 'historic', FILTER_VALIDATE_BOOLEAN);
$streamingType = filter_input(INPUT_GET, 'streamtype', FILTER_SANITIZE_STRING);

if(!empty($url)) {
	if($streamingType === 'shoutcast') {
		if($historic) {
			$urls[] = $url . '/7.html';
			$urls[] = $url . '/played';
			if($nextSong) $urls[] = $url . '/nextsong';
			$curl = curl_multi_init();
			foreach($urls as $key => $value){
				$ch[$key] = curl_init($value);
				curl_setopt($ch[$key], CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch[$key], CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch[$key], CURLOPT_USERAGENT, 'Mozilla/5.0');
				curl_multi_add_handle($curl, $ch[$key]);
			}
			do {
				curl_multi_exec($curl, $running);
				curl_multi_select($curl);
			} while ($running > 0);
			foreach(array_keys($ch) as $key){
				curl_multi_remove_handle($curl, $ch[$key]);
				$content[] = curl_multi_getcontent($ch[$key]);
			}

			$data = $content[0];

			if($nextSong) {
				$nextSongName = (isset($content[2])) ? explode('-', $content[2], 2) : '';

				if(isset($nextSongName[1])) {
					$array['nextSong'] = ['artist' => $nextSongName[0], 'song' => $nextSongName[1]];
				} else {
					$array['nextSong'] = ['artist' => '', 'song' => $nextSongName[0]];
				}
			}

			$pagina = str_replace('</td><td>', '<music>', $content[1], $count);
			$playedSongs = explode('<music>', $pagina);
			unset($playedSongs[0]);
			unset($playedSongs[1]);
			unset($playedSongs[2]);

			foreach($playedSongs as $song) {
				$cutStr = strpos($song, '</td></tr>');
				$playedSong = substr($song, 0, $cutStr);
				$songData = explode('-', $playedSong, 2);
				$songNameHistoric = (!empty($songData[1])) ? trim($songData[1]) : '';
				$array['songHistory'][] = ['artist' => rtrim($songData[0]), 'song' => $songNameHistoric];
			}
			curl_multi_close($curl);
		} else {
			$curl = curl_init($url . '/7.html');

			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0');
			$data = curl_exec($curl);
			curl_close($curl);
		}

		if(!empty($data)) {
			$streamingData = explode(',', $data);

			$array['streamingId'] = $streamingData[1];
			$array['listenersMax'] = $streamingData[2];
			$array['listenersPeak'] = $streamingData[3];
			$array['listeners'] = $streamingData[4];
			$array['transmissionFrequency'] = $streamingData[5];
			$playingNow = $streamingData[6];
			if(count($streamingData) > 7) {
				$playingNow = '';
				for($i = 6; $i < count($streamingData); $i++) {
					$playingNow .= ','.$streamingData[$i];
				}
			}
			$currentSong = explode('-', $playingNow, 2);
			$artist = trim($currentSong[0]);

			if(substr($currentSong[0], 0, 1) === ',') {
				$artist = substr($currentSong[0], 1, -1);
			}

			if(count($currentSong) === 2) {
				if(substr($currentSong[1], -14) === '</body></html>') {
					$array['currentSong'] = (!empty($currentSong[1])) ? trim(substr($currentSong[1], 0, -14)) : '...';
				} else {
					$array['currentSong'] = (!empty($currentSong[1])) ? $currentSong[1] : '...';
				}

				$array['currentArtist'] = (empty($currentSong[1])) ? trim(substr($playingNow, 0, -14)) : $artist;
			} elseif(count($currentSong) < 2) {
				$array['currentSong'] = (empty($playingNow) || $playingNow === '</body></html>') ? '...' : trim(substr($playingNow, 0, -14));
				$array['currentArtist'] = '...';
			} else {
				$array['currentSong'] = trim($currentSong[1]);
				$array['currentArtist'] = trim($artist);
			}
		} else {
			$array = ['error' => 'Неможам да земам информации'];
		}
	} else if ($streamingType === 'icecast'){
		$url_explode = explode("/", $url);
		array_pop($url_explode);
		$url = implode("/", $url_explode);
		$url = $url."/status-json.xsl";
		$data = file_get_contents($url);

		if(!empty($data)) {
			$ice_stats = json_decode($data, true);
			if(is_array($ice_stats["icestats"]["source"])) {
				$ice_stats_source = $ice_stats["icestats"]["source"][0];
			} else {
				$ice_stats_source = $ice_stats["icestats"]["source"];
			}
			$array['listenersPeak'] = $ice_stats_source["listener_peak"];
			$array['listeners'] = $ice_stats_source["listeners"];
			$array['transmissionFrequency'] = $ice_stats_source["bitrate"];	
			$currently_playing = $ice_stats_source["title"];
			$currently_playing = explode(" - ", $currently_playing, 2);
			$array['currentSong'] = $currently_playing[1];
			$array['currentArtist'] = explode(";",$currently_playing[0])[0];
			$track_history = file("player.log");
			$track_list = array_slice($track_history, 0, 20);
			if (stripos($track_history[0], $currently_playing[0]." - ".$currently_playing[1]) === false){
				array_unshift($track_list, $currently_playing[0]." - ".$currently_playing[1]."\n");
				file_put_contents("player.log", $track_list);
			}
		} else {
			$array = ['error' => 'Неможам да земам инфорамции'];
		}

		$track_history = file("player.log");
		array_shift($track_history);

		if($historic) {
			$i = 0;
			foreach ($track_history as $line){
				if($i > 4) continue;
				$track = explode(" - ", $line, 2);
				$last_artist = explode(";",$track[0])[0];
				$last_song = str_replace(array("\n", "\r"), '', $track[1]);
				$array['songHistory'][] = ['artist' => "$last_artist", 'song' => "$last_song"];
				$i++;
			}
		}
	} else {
		$array = ['error' => 'STREAM_TYPE не е пронајден'];
	}
} else {
	$array = ['error' => 'URL параметарот не е пронајден'];
}

$urlHost = $_SERVER['HTTP_HOST'];

header('Access-Control-Allow-Origin: '.$urlHost);
header('Content-type: application/json', true);

echo json_encode($array);
