<?php
/** Private evidence storage, never directly linked as a public image URL. */
function tnts519_save_transfer_photo(?array $file): ?string {
 if(!$file) return null;
 if(!isset($file['error']) || is_array($file['error']))throw new InvalidArgumentException('Invalid photo upload.');
 if((int)$file['error'] === UPLOAD_ERR_NO_FILE) return null;
 if((int)$file['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('The supporting photo could not be uploaded.');
 if((int)($file['size'] ?? 0) < 1 || (int)$file['size'] > 5 * 1024 * 1024) throw new InvalidArgumentException('Photo must be 5 MB or less.');
 if(!is_uploaded_file((string)($file['tmp_name'] ?? ''))) throw new InvalidArgumentException('Invalid photo upload.');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
 $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
 if(!isset($extensions[$mime]) || @getimagesize($file['tmp_name']) === false) throw new InvalidArgumentException('Use a valid JPG, PNG, or WebP photo.');
 $directory=dirname(__DIR__).'/storage/private_transfer_evidence';
 if(!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory))throw new RuntimeException('Could not create private photo storage.');
 // Defense in depth for Apache deployments; other webservers must deny this directory too.
 $deny=$directory.'/.htaccess';
 if(!file_exists($deny) && file_put_contents($deny,"Require all denied\nDeny from all\n")===false) throw new RuntimeException('Unable to secure the evidence directory.');
 $filename=bin2hex(random_bytes(20)).'.'.$extensions[$mime];
 if(!move_uploaded_file($file['tmp_name'],$directory.'/'.$filename))throw new RuntimeException('Could not store the supporting photo.');
 return $filename;
}

function tnts519_delete_transfer_photo(?string $file): void {
 if($file !== null && preg_match('/^[a-f0-9]{40}\.(jpg|png|webp)$/D',$file)){
  $path=dirname(__DIR__).'/storage/private_transfer_evidence/'.$file;
  if(is_file($path))@unlink($path);
 }
}
