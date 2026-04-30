#include "INA219.h"

static uint32_t ina219_calValue = 0;
static uint32_t ina219_currentDivider_mA = 0;
static float    ina219_powerMultiplier_mW = 0.0f;

static void INA219_wireWriteRegister(uint8_t reg, uint16_t value)
{
    Wire.beginTransmission(INA219_ADDRESS);
    Wire.write(reg);
    Wire.write((value >> 8) & 0xFF);
    Wire.write(value & 0xFF);
    Wire.endTransmission();
}

static uint16_t INA219_wireReadRegister(uint8_t reg)
{
    uint16_t buf[2];
    Wire.beginTransmission(INA219_ADDRESS);
    Wire.write(reg);
    Wire.endTransmission();
    Wire.requestFrom((uint8_t)INA219_ADDRESS, (uint8_t)2);
    buf[0] = Wire.read();
    buf[1] = Wire.read();
    return (uint16_t)((buf[0] << 8) | buf[1]);
}

static void INA219_setCalibration_32V_2A()
{
    ina219_calValue             = 4096;
    ina219_currentDivider_mA   = 1;
    ina219_powerMultiplier_mW  = 20.0f;

    INA219_wireWriteRegister(INA219_REG_CALIBRATION, ina219_calValue);

    uint16_t config = INA219_CONFIG_BVOLTAGERANGE_32V |
                      INA219_CONFIG_GAIN_8_320MV      |
                      INA219_CONFIG_BADCRES_12BIT      |
                      INA219_CONFIG_SADCRES_12BIT_32S_17MS |
                      INA219_CONFIG_MODE_SANDBVOLT_CONTINUOUS;
    INA219_wireWriteRegister(INA219_REG_CONFIG, config);
}

// Csak a kalibráció beállítása – Wire.begin() az .ino setup()-ban fut le
void INA219_begin()
{
    INA219_setCalibration_32V_2A();
}

float INA219_getBusVoltage_V()
{
    uint16_t value = INA219_wireReadRegister(INA219_REG_BUSVOLTAGE);
    return (int16_t)((value >> 3) * 4) * 0.001f;
}

float INA219_getCurrent_mA()
{
    INA219_wireWriteRegister(INA219_REG_CALIBRATION, ina219_calValue);
    uint16_t value = INA219_wireReadRegister(INA219_REG_CURRENT);
    float valueDec = (float)(int16_t)value;
    valueDec /= (float)ina219_currentDivider_mA;
    return valueDec;
}

float INA219_getPower_mW()
{
    INA219_wireWriteRegister(INA219_REG_CALIBRATION, ina219_calValue);
    uint16_t value = INA219_wireReadRegister(INA219_REG_POWER);
    float valueDec = (float)(int16_t)value;
    valueDec *= ina219_powerMultiplier_mW;
    return valueDec;
}
