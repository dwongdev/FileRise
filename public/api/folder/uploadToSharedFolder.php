<?php
// public/api/folder/uploadToSharedFolder.php

/**
 * @OA\Post(
 *   path="/api/folder/uploadToSharedFolder.php",
 *   summary="Upload a file into a shared folder (by token)",
 *   description="Public form-upload endpoint. Only allowed when the share link has uploads enabled. On success responds with a redirect to the share page.",
 *   operationId="uploadToSharedFolder",
 *   tags={"Shared Folders"},
 *   @OA\RequestBody(
 *     required=true,
 *     content={
 *       "multipart/form-data": @OA\MediaType(
 *         mediaType="multipart/form-data",
 *         @OA\Schema(
 *           type="object",
 *           required={"token","fileToUpload"},
 *           @OA\Property(property="token", type="string", description="Share token"),
 *           @OA\Property(property="pass", type="string", description="Share password (if required)"),
 *           @OA\Property(property="path", type="string", description="Optional subfolder path within the shared folder"),
 *           @OA\Property(property="relativePath", type="string", description="Optional relative path for folder uploads"),
 *           @OA\Property(property="fileToUpload", type="string", format="binary", description="File to upload"),
 *           @OA\Property(property="resumableChunkNumber", type="integer", description="Chunk number for chunked upload"),
 *           @OA\Property(property="resumableTotalChunks", type="integer", description="Total chunks"),
 *           @OA\Property(property="resumableIdentifier", type="string", description="Chunk upload identifier"),
 *           @OA\Property(property="resumableFilename", type="string", description="Original file name"),
 *           @OA\Property(property="resumableRelativePath", type="string", description="Original relative path"),
 *           @OA\Property(property="file", type="string", format="binary", description="Chunk payload when chunked")
 *         )
 *       )
 *     }
 *   ),
 *   @OA\Response(response=302, description="Redirect to /api/folder/shareFolder.php?token=..."),
 *   @OA\Response(response=400, description="Upload error or invalid input"),
 *   @OA\Response(response=405, description="Method not allowed")
 * )
 */

require_once __DIR__ . '/../../../config/config.php';

$folderController = new \FileRise\Http\Controllers\FolderController();
$folderController->uploadToSharedFolder();
