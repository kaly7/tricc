#include "INA219.h"

float bus_voltage = 0;
float shunt_voltage = 0;
float power = 0;
float current = 0;
float P=0;

void setup() {
    INA219_init();
}

void loop() {
        bus_voltage = INA219_getBusVoltage_V();         // voltage on V- (load side)
        current = INA219_getCurrent_mA()/1000;               // current in mA
        power = INA219_getPower_mW() / 1000.0;
        P = (bus_voltage -3)/1.2*100;
        if(P<0) P=0;
        else if (P>100) P=100;

        Serial.print("Voltage: ");
        Serial.print(String(bus_voltage,3));
        Serial.println("V");
        Serial.print("Current:  ");
        Serial.print(String(current,3));
        Serial.println("A");
        Serial.print("Power:  ");
        Serial.print(String(power,3));
        Serial.println("W");
        Serial.print("Percent: ");
        Serial.print(String(P,1));
        Serial.println(" %");
        Charge_indication();
        Serial.println();
        delay(500);
}
