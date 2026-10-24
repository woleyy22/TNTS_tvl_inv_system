<?php
/** Private optional Phase 7 supporting evidence. Never serve the storage path directly. */
function p72_save_evidence(?array $file, bool $allowPdf=false): ?string {
 if(!$file)return null;
 if(!isset($file['error'])||is_array($file['error']))throw new RuntimeException('Invalid evidence upload.');
 if((int)$file['error']===UPLOAD_ERR_NO_FILE)return null;
 if((int)$file['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Evidence upload failed.');
 if((int)($file['size']??0)<1||(int)$file['size']>5*1024*1024)throw new RuntimeException('Evidence must be 5 MB or smaller.');
 $tmp=(string)($file['tmp_name']??'');if(!is_uploaded_file($tmp))throw new RuntimeException('Invalid evidence upload.');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
 $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
 if($allowPdf)$types['application/pdf']='pdf';
 if(!isset($types[$mime]))throw new RuntimeException($allowPdf?'Use a JPG, PNG, WebP or PDF file.':'Use a JPG, PNG or WebP photo.');
 if($mime==='application/pdf'){
   $f=fopen($tmp,'rb');$head=$f?fread($f,5):'';if($f)fclose($f);
   if($head!=='%PDF-')throw new RuntimeException('Invalid PDF evidence.');
 }elseif(@getimagesize($tmp)===false)throw new RuntimeException('Invalid photo evidence.');
 $dir=dirname(__DIR__).'/storage/private_phase7_evidence';
 if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Could not create private evidence storage.');
 $deny=$dir.'/.htaccess';if(!is_file($deny)&&file_put_contents($deny,"Require all denied\nDeny from all\n")===false)throw new RuntimeException('Could not protect evidence storage.');
 $filename=bin2hex(random_bytes(20)).'.'.$types[$mime];
 if(!move_uploaded_file($tmp,$dir.'/'.$filename))throw new RuntimeException('Could not save evidence.');
 return $filename;
}
function p72_delete_evidence(?string $filename): void {
 if($filename && preg_match('/^[a-f0-9]{40}\.(jpg|png|webp|pdf)$/D',$filename)){
   $path=dirname(__DIR__).'/storage/private_phase7_evidence/'.$filename;
   if(is_file($path))@unlink($path);
 }
}
