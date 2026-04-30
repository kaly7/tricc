#include <Adafruit_NeoPixel.h>

#define LED_PIN   13   // GPIO13 -> DIN
#define LED_COUNT  7   // hány LED van a gyűrűn

Adafruit_NeoPixel ring(LED_COUNT, LED_PIN, NEO_GRB + NEO_KHZ800);

void setup() {
  Serial.begin(115200);
  delay(500);
  Serial.println("LED teszt indul...");

  ring.begin();
  ring.setBrightness(50);
  ring.clear();
  ring.show();
  delay(200);
}

void loop() {
  // Egyenként felvillanás: PIROS
  Serial.println("--- Egyenkent piros ---");
  for (int i = 0; i < LED_COUNT; i++) {
    ring.clear();
    ring.setPixelColor(i, ring.Color(200, 0, 0));
    ring.show();
    Serial.printf("  LED %d: piros\n", i);
    delay(500);
  }
  ring.clear();
  ring.show();
  delay(300);

  // Mind egyszerre: ZOLD
  Serial.println("Mind zold");
  for (int i = 0; i < LED_COUNT; i++) ring.setPixelColor(i, ring.Color(0, 200, 0));
  ring.show();
  delay(1000);
  ring.clear();
  ring.show();
  delay(300);

  // Mind egyszerre: KEK
  Serial.println("Mind kek");
  for (int i = 0; i < LED_COUNT; i++) ring.setPixelColor(i, ring.Color(0, 0, 200));
  ring.show();
  delay(1000);
  ring.clear();
  ring.show();
  delay(300);

  // Mind egyszerre: FEHER
  Serial.println("Mind feher");
  for (int i = 0; i < LED_COUNT; i++) ring.setPixelColor(i, ring.Color(200, 200, 200));
  ring.show();
  delay(1000);
  ring.clear();
  ring.show();
  delay(1000);
}
