<?php
header('Content-Type: application/json');

// Capture the correct request URI
$request_uri = trim($_SERVER['REQUEST_URI'], '/');
error_log("routes.php is running! Request: " . $_SERVER['REQUEST_URI']);
error_log("Processed request URI: " . $request_uri);

/**
 * @OA\Info(
 *     title="StormPigs API",
 *     version="1.0",
 *     description="API for retrieving songs, jam sessions, and song files"
 * )
 */

/**
 * @OA\Get(
 *     path="/api/songs",
 *     summary="Retrieve all songs",
 *     @OA\Response(
 *         response=200,
 *         description="A list of songs",
 *         @OA\JsonContent(
 *             type="array",
 *             @OA\Items(ref="#/components/schemas/Song")
 *         )
 *     )
 * )
 */
if ($request_uri === 'api/songs') {
    error_log("Matched route: api/songs");
    require_once 'controllers/SongController.php';
    SongController::getAllSongs();
    exit;
}

/**
 * @OA\Get(
 *     path="/api/jams",
 *     summary="Retrieve all jam sessions",
 *     @OA\Response(
 *         response=200,
 *         description="A list of jam sessions",
 *         @OA\JsonContent(
 *             type="array",
 *             @OA\Items(ref="#/components/schemas/JamSession")
 *         )
 *     )
 * )
 */
if ($request_uri === 'api/jams') {
    error_log("Matched route: api/jams");
    require_once 'controllers/JamController.php';
    JamController::getAllJams();
    exit;
}

/**
 * @OA\Get(
 *     path="/api/files/song/{song_id}",
 *     summary="Retrieve files associated with a specific song",
 *     @OA\Parameter(
 *         name="song_id",
 *         in="path",
 *         required=true,
 *         description="The ID of the song",
 *         @OA\Schema(type="integer", example=1)
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="A list of files for the specified song",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="file_id", type="integer", example=10),
 *             @OA\Property(property="file_name", type="string", example="song.mp3"),
 *             @OA\Property(property="file_type", type="string", example="audio")
 *         )
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="No file found for this song",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="error", type="string", example="No file found for this song")
 *         )
 *     )
 * )
 */
if (preg_match('/api\\/files\\/song\\/(\\d+)/', $request_uri, $matches)) {
    error_log("Matched route: api/files/song/" . $matches[1]);
    require_once 'controllers/FileController.php';
    FileController::getFilesBySong($matches[1]);
    exit;
}

/**
 * @OA\Get(
 *     path="/api/files/jam/{jam_id}",
 *     summary="Retrieve files associated with a specific jam session",
 *     @OA\Parameter(
 *         name="jam_id",
 *         in="path",
 *         required=true,
 *         description="The ID of the jam session",
 *         @OA\Schema(type="integer", example=5)
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="A list of files for the specified jam session",
 *         @OA\JsonContent(
 *             type="array",
 *             @OA\Items(
 *                 @OA\Property(property="file_id", type="integer", example=10),
 *                 @OA\Property(property="file_name", type="string", example="jam5_song.mp3"),
 *                 @OA\Property(property="file_type", type="string", example="audio")
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="No files found for this jam session",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="error", type="string", example="No files found for this jam session, but songs exist")
 *         )
 *     )
 * )
 */
if (preg_match('/api\\/files\\/jam\\/(\\d+)/', $request_uri, $matches)) {
    error_log("Matched route: api/files/jam/" . $matches[1]);
    require_once 'controllers/FileController.php';
    FileController::getFilesByJam($matches[1]);
    exit;
}

?>

