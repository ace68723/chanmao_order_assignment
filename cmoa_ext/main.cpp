#include <phpcpp.h>
#include <stdio.h>
#include <stdlib.h>
#include "jobSchedule.h"

bool parseInput(Php::Parameters &paras)
{
    vector<CDriver> &drivers = ALG::drivers;
    vector<CTask> &tasks = ALG::tasks;
    int &nLocations = ALG::nLocations;
    double (&map)[MAXNLOCATIONS][MAXNLOCATIONS] = ALG::map;
    //CTime curTime;
    if (paras.size() != 1) {
        //cout<< "parse failed:" << reader.getFormattedErrorMessages() << endl;
        printf("takes exactly 1 parameter, given %lu", paras.size()); 
        return false;
    }
    auto root = paras[0];
    Php::Value driversA = root["drivers"];
    Php::Value tasksA = root["tasks"];
    Php::Value distMatA = root["distMat"];
    nLocations = root["nLocations"];
    drivers.clear(); tasks.clear();
    for (int i=0; i<driversA.size(); i++) {
        CID driverID = driversA[i]["did"];
        CTime availableTime = driversA[i]["availableTime"];
        CTime offTime = driversA[i]["offTime"];
        CLocationID location = driversA[i]["location"];
        int maxNOrder = driversA[i]["maxNOrder"];
        double distFactor = driversA[i]["distFactor"];
        double pickFactor = driversA[i]["pickFactor"];
        double deliFactor = driversA[i]["deliFactor"];
        //CDriver(int driverID=NULL_ID, CTime availableTime=0, CTime offTime=0, int location=NULL_ID, int maxNOrder=0, double distFactor=1, double pickFactor=1, double deliFactor=1)
        drivers.push_back(CDriver(driverID, availableTime, offTime, location, maxNOrder, distFactor, pickFactor, deliFactor));
    }
    for (int i=0; i<tasksA.size(); i++) {
        CID tid = tasksA[i]["tid"];
        CLocationID location = tasksA[i]["location"];
        CTime deadline = tasksA[i]["deadline"]; 
        CTime readyTime = tasksA[i]["readyTime"];  
        CTime execTime = tasksA[i]["execTime"];  
        CID did = tasksA[i]["did"];
        CID prevTask = tasksA[i]["prevTask"];
        CID nextTask = tasksA[i]["nextTask"];
        double rwdOneTime = tasksA[i]["rwdOneTime"];
        double pnlOneTime = tasksA[i]["pnlOneTime"];
        double rwdPerSec = tasksA[i]["rwdPerSec"];
        double pnlPerSec = tasksA[i]["pnlPerSec"];
        //CTask(int tid=NULL_ID, int location=NULL_ID, CTime deadline=0, CTime readyTime=0, CRTime execTime=0, int asgnDriver=NULL_ID, int prevTask=NULL_ID, int nextTask=NULL_ID, double rwdOneTime=0, double pnlOneTime=0, double rwdPerSec=0, double pnlPerSec=0)
        tasks.push_back(CTask(tid,location,deadline,readyTime,execTime,did,prevTask,nextTask,rwdOneTime, pnlOneTime, rwdPerSec, pnlPerSec));
    }
    if (nLocations > distMatA.size()) {
        return E_CONFLICT_SETTING;
    }
    for (int i=0; i<nLocations; i++) {
        for (int j=0; j<nLocations; j++) {
            map[i][j] = root["distMat"][i][j];
        }
    }
    printf("read %lu drivers, %lu tasks:\n", drivers.size(), tasks.size());
    for (unsigned int i=0; i<drivers.size(); i++) {
        printf("driver %d:  offTime = %.1f\n", i, drivers[i].offTime);
    }
    return true;
}

Php::Value prepareOutput(int ret, vector<CScheduleItem> &schedule) 
{
    Php::Value root;
    root["ev_error"] = ret;
    if (ret != E_NORMAL) {
        return root;
    }
    Php::Value schd;
    Php::Value schdItem;
    for (unsigned int i=0; i<schedule.size(); i++)
    {
        schdItem["did"] = schedule[i].did;
        Php::Value taskList;
        Php::Value completeTime;
        for (unsigned int j=0; j<schedule[i].tids.size(); j++) {
            taskList[j] = schedule[i].tids[j];
            completeTime[j] = schedule[i].completeTime[j];
        }
        schdItem["tids"] = taskList;
        schdItem["completeTime"] = completeTime;
        schd[i] = schdItem;
    }
    root["schedules"] = schd;
    return root;
}

Php::Value Method(Php::Parameters &paras) 
{
    int ret = E_CONFLICT_SETTING;
    if (parseInput(paras)) {
        ret = ALG::findScheduleGreedy();
        if (ret != E_NORMAL) {
            printf("search algorithm returned %d.\n", ret);
        }
    }
    return prepareOutput(ret, ALG::schedule);
}

/**
 *  tell the compiler that the get_module is a pure C function
 */
extern "C" {

    /**
     *  Function that is called by PHP right after the PHP process
     *  has started, and that returns an address of an internal PHP
     *  strucure with all the details and features of your extension
     *
     *  @return void*   a pointer to an address that is understood by PHP
     */
    PHPCPP_EXPORT void *get_module() 
    {
        // static(!) Php::Extension object that should stay in memory
        // for the entire duration of the process (that's why it's static)
        static Php::Extension ext("cmoa-ext", "1.0");
        ext.add<Method>("cmoa_schedule");
        return ext;
    }
}
