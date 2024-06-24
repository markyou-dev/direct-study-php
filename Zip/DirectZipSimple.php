<?php
class DirectZipSimple {
    public function excute($files) {
        $zipFileName = 'download.zip'; // ZIP 파일 이름

        // ZIP 파일 핸들 열기
        $zip = fopen($zipFileName, 'w');

        if ($zip) {
            $offset = 0;
            // 각 파일을 순회하며 ZIP 포맷에 맞게 데이터 쓰기
            foreach ($files as $name => $path) {
                // 파일이 존재하는지 확인 후 추가
                if (file_exists($path)) {
                    $fileContent = file_get_contents($path);
                    $fileSize = strlen($fileContent);

                    // 파일 수정 시간 가져오기
                    $fileModTime = filemtime($path);

                    // 파일명 길이 및 파일명
                    $nameLength = strlen($name);

                    // 로컬 파일 헤더 추가
                    $localFileHeader = "\x50\x4b\x03\x04"; // Local file header signature
                    $localFileHeader .= "\x14\x00"; // Version needed to extract (minimum)
                    $localFileHeader .= "\x00\x00"; // General purpose bit flag (no compression)
                    $localFileHeader .= "\x00\x00"; // Compression method (no compression)
                    $localFileHeader .= pack('V', $fileModTime); // Last mod file time and date
                    $localFileHeader .= pack('V', crc32($fileContent)); // CRC-32
                    $localFileHeader .= pack('V', $fileSize); // Compressed size (same as uncompressed)
                    $localFileHeader .= pack('V', $fileSize); // Uncompressed size
                    $localFileHeader .= pack('v', $nameLength); // File name length
                    $localFileHeader .= pack('v', 0); // Extra field length
                    fwrite($zip, $localFileHeader);
                    fwrite($zip, $name);
                    fwrite($zip, $fileContent);

                    // Offset 업데이트
                    $offset += strlen($localFileHeader) + $nameLength + $fileSize;
                } else {
                    echo "File not found: $path";
                }
            }

            // End of central directory record 추가
            $endOfCentralDirectory = "\x50\x4b\x05\x06"; // End of central directory signature
            $endOfCentralDirectory .= "\x00\x00"; // Number of this disk
            $endOfCentralDirectory .= "\x00\x00"; // Disk where central directory starts
            $endOfCentralDirectory .= pack('v', count($files)); // Number of entries in central directory on this disk
            $endOfCentralDirectory .= pack('v', count($files)); // Total number of entries in central directory
            $endOfCentralDirectory .= pack('V', strlen($localFileHeader) + $nameLength + $fileSize * count($files)); // Size of central directory
            $endOfCentralDirectory .= pack('V', $offset); // Offset of start of central directory
            $endOfCentralDirectory .= "\x00\x00"; // Comment length
            fwrite($zip, $endOfCentralDirectory);

            fclose($zip);

            // ZIP 파일 다운로드 설정
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
            header('Content-Length: ' . filesize($zipFileName));

            // ZIP 파일 출력
            readfile($zipFileName);

            // 임시 파일 삭제
            unlink($zipFileName);
        } else {
            echo "Failed to create ZIP file";
        }
    }
}
