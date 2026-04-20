<?php
// Minimal PDF generator with JPEG and basic PNG (truecolor/grayscale, no alpha/palette).
// Tested with PHP 7.4+ / 8.x.
// Not a full-featured PDF library.

class SimplePDF {
  private array $objects = [];
  private array $pages = [];
  private array $images = [];
  private int $objNum = 0;

  private float $wPt = 595.28; // A4
  private float $hPt = 841.89;
  private float $margin = 36; // 0.5 inch
  private float $cursorY = 0;
  private int $currentPageIndex = -1;
  private string $currentContent = '';

  public function __construct() {}

  public function addPage(): void {
    if ($this->currentPageIndex >= 0) {
      $this->pages[$this->currentPageIndex]['content'] = $this->currentContent;
    }
    $this->currentPageIndex++;
    $this->cursorY = $this->hPt - $this->margin;
    $this->currentContent = '';
    $this->pages[$this->currentPageIndex] = [
      'content' => '',
      'resources' => ['XObject'=>[]]
    ];
    // default font
    $this->setFont('Helvetica', 11);
  }

  private string $font = 'Helvetica';
  private float $fontSize = 11;

  public function setFont(string $name, float $size): void {
    $this->font = $name;
    $this->fontSize = $size;
  }

  public function ln(float $h = 14): void {
    $this->cursorY -= $h;
  }

  public function text(float $x, float $y, string $txt): void {
    $txt = $this->escape($txt);
    $this->currentContent .= sprintf("BT /F1 %.2f Tf %.2f %.2f Td (%s) Tj ET\n", $this->fontSize, $x, $y, $txt);
  }

  public function writeLine(string $txt, float $x = 0, ?float $y = null): void {
    if ($y === null) $y = $this->cursorY;
    $this->text($this->margin + $x, $y, $txt);
    $this->ln(14);
  }

  // Draw a simple rectangle (stroke)
  public function rect(float $x, float $y, float $w, float $h): void {
    $this->currentContent .= sprintf("%.2f %.2f %.2f %.2f re S\n", $x, $y, $w, $h);
  }

  public function image(string $path, float $x, float $y, float $w = 0, float $h = 0): void {
    $img = $this->loadImage($path);
    if ($w <= 0 && $h <= 0) {
      $w = $img['w'] * 0.24;
      $h = $img['h'] * 0.24;
    } elseif ($w <= 0) {
      $w = $h * ($img['w'] / $img['h']);
    } elseif ($h <= 0) {
      $h = $w * ($img['h'] / $img['w']);
    }

    $name = $img['name'];
    $this->pages[$this->currentPageIndex]['resources']['XObject'][$name] = $img['obj'];

    // q ... cm /Im1 Do Q
    $this->currentContent .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n",
      $w, $h, $x, $y, $name
    );
  }

  private function loadImage(string $path): array {
    $real = $path;
    if (!is_file($real)) {
      throw new RuntimeException("Image not found: ".$path);
    }
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $key = md5($real);
    if (isset($this->images[$key])) return $this->images[$key];

    if ($ext === 'jpg' || $ext === 'jpeg') {
      $info = $this->parseJpeg($real);
    } elseif ($ext === 'png') {
      $info = $this->parsePng($real);
    } else {
      throw new RuntimeException("Unsupported image type: ".$ext);
    }

    $this->objNum++;
    $objId = $this->objNum;
    $name = "Im".$objId;

    $info['obj_id'] = $objId;
    $info['name'] = $name;

    $this->images[$key] = [
      'obj' => $objId,
      'name' => $name,
      'w' => $info['w'],
      'h' => $info['h'],
      'stream' => $info['stream'],
      'dict' => $info['dict'],
    ];
    return $this->images[$key];
  }

  private function parseJpeg(string $path): array {
    $data = file_get_contents($path);
    if ($data === false) throw new RuntimeException("Cannot read JPEG");
    // get size
    $size = @getimagesize($path);
    if (!$size) throw new RuntimeException("Bad JPEG");
    [$w, $h] = $size;

    $dict = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>",
      $w, $h, strlen($data)
    );

    return ['w'=>$w,'h'=>$h,'stream'=>$data,'dict'=>$dict];
  }

  private function parsePng(string $path): array {
    $f = fopen($path, 'rb');
    if (!$f) throw new RuntimeException("Cannot open PNG");
    $sig = fread($f, 8);
    if ($sig !== "\x89PNG\r\n\x1a\n") throw new RuntimeException("Bad PNG signature");

    $w = $h = 0; $bitDepth=0; $colorType=0;
    $idat = '';
    $hasAlpha = false;
    while (!feof($f)) {
      $lenData = fread($f, 4);
      if (strlen($lenData) !== 4) break;
      $len = unpack('N', $lenData)[1];
      $type = fread($f, 4);
      $chunk = ($len>0) ? fread($f, $len) : '';
      fread($f, 4); // crc

      if ($type === 'IHDR') {
        $w = unpack('N', substr($chunk,0,4))[1];
        $h = unpack('N', substr($chunk,4,4))[1];
        $bitDepth = ord($chunk[8]);
        $colorType = ord($chunk[9]);
        if ($bitDepth !== 8) throw new RuntimeException("PNG bit depth not supported (only 8): ".$bitDepth);
        if ($colorType === 4 || $colorType === 6) $hasAlpha = true;
        if (!in_array($colorType, [0,2], true)) {
          // 0 grayscale, 2 truecolor. (We deliberately don't support palette/alpha in this minimal lib.)
          throw new RuntimeException("PNG color type not supported (need grayscale or truecolor without alpha). colorType=".$colorType);
        }
      } elseif ($type === 'IDAT') {
        $idat .= $chunk;
      } elseif ($type === 'IEND') {
        break;
      }
    }
    fclose($f);

    if ($w<=0 || $h<=0) throw new RuntimeException("PNG size missing");
    if ($hasAlpha) throw new RuntimeException("PNG alpha not supported by SimplePDF. Export signature with white background.");

    $colors = ($colorType === 2) ? 3 : 1;
    // Use PNG predictor (15) so we can embed the filtered scanlines (with filter bytes) directly.
    $dict = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors %d /BitsPerComponent 8 /Columns %d >> /Length %d >>",
      $w, $h, $colors, $w, strlen($idat)
    );
    if ($colors === 1) {
      $dict = str_replace("/DeviceRGB", "/DeviceGray", $dict);
    }
    return ['w'=>$w,'h'=>$h,'stream'=>$idat,'dict'=>$dict];
  }

  private function escape(string $s): string {
    return str_replace(['\\','(',')',"\r","\n"], ['\\\\','\\(','\\)',' ',' '], $s);
  }

  public function output(string $filePath): void {
    if ($this->currentPageIndex < 0) $this->addPage();
    $this->pages[$this->currentPageIndex]['content'] = $this->currentContent;

    $out = "%PDF-1.4\n";
    $offsets = [0];

    // 1) Font object (/F1)
    $fontObj = $this->newObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");

    // 2) Image objects
    $imgObjIds = [];
    foreach ($this->images as $img) {
      $imgObjIds[$img['obj']] = true;
    }

    // 3) Page content streams and page objects will be created after catalog/pages

    // We'll assemble objects in numeric order.
    // Reserve object IDs: fontObj is 1.
    // Our $this->objNum already counted images, but content/page need new ids.
    $maxId = $this->objNum;

    // Create image objects content
    foreach ($this->images as $img) {
      $id = $img['obj'];
      $this->objects[$id] = [
        'dict' => $img['dict'],
        'stream' => $img['stream'],
      ];
      if ($id > $maxId) $maxId = $id;
    }

    // Pages tree object id
    $pagesTreeId = ++$maxId;

    // For each page: content object + page object
    $pageObjIds = [];
    $contentObjIds = [];
    foreach ($this->pages as $pi => $pg) {
      $contentId = ++$maxId;
      $pageId = ++$maxId;
      $contentObjIds[$pi] = $contentId;
      $pageObjIds[$pi] = $pageId;

      $content = $pg['content'];
      $this->objects[$contentId] = [
        'dict' => sprintf("<< /Length %d >>", strlen($content)),
        'stream' => $content
      ];

      // resources
      $xobjs = '';
      foreach ($pg['resources']['XObject'] as $name => $objId) {
        $xobjs .= sprintf("/%s %d 0 R ", $name, $objId);
      }
      $res = "<< /Font << /F1 {$fontObj} 0 R >>";
      if ($xobjs !== '') $res .= " /XObject << {$xobjs} >>";
      $res .= " >>";

      $this->objects[$pageId] = [
        'dict' => "<< /Type /Page /Parent {$pagesTreeId} 0 R /MediaBox [0 0 {$this->wPt} {$this->hPt}] /Resources {$res} /Contents {$contentId} 0 R >>",
        'stream' => null
      ];
    }

    // Pages tree
    $kids = '';
    foreach ($pageObjIds as $pid) $kids .= "{$pid} 0 R ";
    $this->objects[$pagesTreeId] = [
      'dict' => "<< /Type /Pages /Count ".count($pageObjIds)." /Kids [{$kids}] >>",
      'stream' => null
    ];

    // Catalog object
    $catalogId = ++$maxId;
    $this->objects[$catalogId] = [
      'dict' => "<< /Type /Catalog /Pages {$pagesTreeId} 0 R >>",
      'stream' => null
    ];

    // Write all objects in order 1..maxId
    $xref = [];
    for ($i=1; $i<=$maxId; $i++) {
      $xref[$i] = strlen($out);
      $out .= "{$i} 0 obj\n";
      $obj = $this->objects[$i] ?? null;
      if ($i === $fontObj) {
        $out .= "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\n";
      } elseif ($obj) {
        $out .= $obj['dict']."\n";
        if ($obj['stream'] !== null) {
          $out .= "stream\n".$obj['stream']."\nendstream\n";
        }
      } else {
        // should not happen
        $out .= "<<>>\n";
      }
      $out .= "endobj\n";
    }

    // xref
    $xrefPos = strlen($out);
    $out .= "xref\n0 ".($maxId+1)."\n";
    $out .= "0000000000 65535 f \n";
    for ($i=1; $i<=$maxId; $i++) {
      $out .= sprintf("%010d 00000 n \n", $xref[$i]);
    }

    // trailer
    $out .= "trailer\n<< /Size ".($maxId+1)." /Root {$catalogId} 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

    if (file_put_contents($filePath, $out) === false) {
      throw new RuntimeException("Cannot write PDF: ".$filePath);
    }
  }

  private function newObj(string $dict, ?string $stream=null): int {
    $this->objNum++;
    $id = $this->objNum;
    $this->objects[$id] = ['dict'=>$dict,'stream'=>$stream];
    return $id;
  }
}
