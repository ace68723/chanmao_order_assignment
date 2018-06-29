#include <stdio.h>
#include "jobSchedule.h"

const char* version = "0.1.3"; //add meters from input to output
double ALG::map[MAXNLOCATIONS][MAXNLOCATIONS];
double ALG::meterMap[MAXNLOCATIONS][MAXNLOCATIONS];
vector<CDriver> ALG::drivers;
vector<CTask> ALG::tasks;
int ALG::nLocations;
vector<CScheduleItem> ALG::schedule;

#define IN_RANGE(x,n) ((x>=0)&&(x<n))
#define IN_RANGE_OR_NULL(x,n) ((x==NULL_ID)||((x>=0)&&(x<n)))

#define MOVING_TIME(i,j,driver) (map[i][j]*(driver).distFactor)
#define MOVING_METERS(i,j,driver) (meterMap[i][j])
#define METER_TO_EVA_RATIO 0.1

CTime calNextAvailable(CTime tArrive, CTask &task, CDriver &driver)
{
    CTime ret = tArrive;
    if (task.readyTime > tArrive) 
        ret = task.readyTime;
    int mExecTime = task.execTime*(task.prevTask==NULL_ID ? driver.pickFactor:driver.deliFactor);
    ret += mExecTime;
    return ret;
}

double ALG::calEva(CDriver &driver, unsigned int n, int tids[])
{
    CTime curTime = driver._available;
    int curLoc = driver._loc;
    double eva = 0;
    for (unsigned int i=0; i<n; i++) {
        int iNext = tids[i];
        CTask &task = tasks[iNext];
        double dist = MOVING_TIME(curLoc,task.location,driver);
        double meters = MOVING_METERS(curLoc,task.location,driver);
        curTime += dist; 
        curTime = calNextAvailable(curTime, task, driver);
        curLoc = task.location;
        eva -= meters * METER_TO_EVA_RATIO;
        if (curTime > task.deadline) {
            eva -= task.pnlOneTime + task.pnlPerSec*(curTime-task.deadline);
        }
        else {
            eva += task.rwdOneTime + task.rwdPerSec*(task.deadline-curTime);
        }
    }
    return eva;
}
double ALG::calMeters(CDriver &driver, unsigned int n, int tids[])
{
    int curLoc = driver._loc;
    double total = 0;
    for (unsigned int i=0; i<n; i++) {
        int iNext = tids[i];
        CTask &task = tasks[iNext];
        double meters = MOVING_METERS(curLoc,task.location,driver);
        curLoc = task.location;
        total += meters;
    }
    return total;
}
void ALG::calFTime(CDriver &driver, CTime fTimes[])
{
    int curLoc = driver._loc;
    CTime fTime = driver._available;
    for (unsigned int i=0; i<driver._assignedTasks.size(); i++) {
        int iNext = driver._assignedTasks[i];
        fTime += MOVING_TIME(curLoc,tasks[iNext].location,driver);
        fTime = calNextAvailable(fTime, tasks[iNext], driver);
        curLoc = tasks[iNext].location;
        fTimes[i] = fTime;
    }
}

void ALG::setAssignedTasks(CDriver &driver, unsigned int n, int bestSol[MAXNPARALLELTASKS]) {
    driver._assignedTasks.clear();
    driver._nOrder = 0;
    for (unsigned int i=0; i<n; i++) {
        driver._assignedTasks.push_back(bestSol[i]);
        driver._nOrder += (tasks[bestSol[i]].nextTask==NULL_ID);
    }
    driver._eva = calEva(driver, n, bestSol);
    driver._meters = calMeters(driver, n, bestSol);
}
bool ALG::arrange_future_tasks(CDriver &driver)
{
    if (driver._nOrder >= 0) return true;
    double bestFTime = -1;
    double bestEva = 0;
    int bestSol[MAXNPARALLELTASKS];
    int tids[MAXNPARALLELTASKS];
    int sol[MAXNPARALLELTASKS];
    bool taskDoneMark[MAXNTASKS];
    unsigned int n = driver._assignedTasks.size();
    if (n>MAXNPARALLELTASKS) return false;
    driver._nOrder = 0;
    for (unsigned int i=0; i<driver._assignedTasks.size(); i++) {
        tids[i] = driver._assignedTasks[i]; taskDoneMark[tids[i]] = false;
        driver._nOrder += (tasks[tids[i]].nextTask==NULL_ID);
    }
    //vector<bool> mark(n, false);
    _searchwEva(driver, driver._available, driver._loc, 0, n, tids, sol, taskDoneMark, bestFTime, bestSol, bestEva);
    if (bestFTime<0) return false;
    if (n != driver._assignedTasks.size()) return false;
    for (unsigned int j=0; j<n; j++) {
        int idx = bestSol[j];
        driver._assignedTasks[j] = idx;
    }
    driver._eva = calEva(driver, n, bestSol);
    driver._meters = calMeters(driver, n, bestSol);
    return true;
}

int ALG::formatSchedule(unsigned int nDriver)
{
    //set up schedule according to each driver's taskList
    schedule.clear();
    for (unsigned int i=0; i<nDriver && i<drivers.size(); i++) 
    {
        CScheduleItem si;
        si.did = drivers[i].did;
        si.eva = drivers[i]._eva;
        si.meters = drivers[i]._meters;
        si.tids.clear();
        si.completeTime.clear();
        CTime fTimes[MAXNPARALLELTASKS];
        calFTime(drivers[i], fTimes);
        for (unsigned int j=0; j<drivers[i]._assignedTasks.size(); j++) {
            int idx = drivers[i]._assignedTasks[j];
            si.tids.push_back(idx);
            si.completeTime.push_back(fTimes[j]);
        }
        schedule.push_back(si);
    }
    return E_NORMAL;
}

//assume each task has only one prevTask or nextTask
int ALG::findScheduleGreedy()
{
    int ret = preProcess();
    if (ret != E_NORMAL) {
        printf("failed check\n");
        return ret;
    }
    vector<int> unassigned_first_tasks;
    for (unsigned int ti=0; ti<tasks.size(); ti++) if (tasks[ti].did == NULL_ID && tasks[ti].prevTask==NULL_ID)
        unassigned_first_tasks.push_back(ti);
    for(unsigned int countIdx = 0; countIdx < unassigned_first_tasks.size(); countIdx++)
    {
        int select_task_uaidx = NULL_ID;
        double bestEvaDiff = 0;
        int select_driver = NULL_ID;
        int bestN;
        int bestSol[MAXNPARALLELTASKS];
        for(unsigned int ii=0; ii<unassigned_first_tasks.size(); ii++) {
            int ti = unassigned_first_tasks[ii];
            if (ti == NULL_ID) continue;
            for (unsigned int di=0; di<drivers.size(); di++) {
                double evaDiff;
                unsigned int n;
                int sol[MAXNPARALLELTASKS];
                if (tryAssignOrderToDriver(ti,drivers[di],evaDiff,n,sol)) {
                    if (select_driver == NULL_ID || bestEvaDiff < evaDiff) {
                        select_driver = di;
                        select_task_uaidx = ii;
                        bestEvaDiff = evaDiff;
                        bestN = n;
                        for(unsigned int i=0; i<n; i++) bestSol[i] = sol[i];
                    }
                }
            }
        }
        if (select_driver == NULL_ID) break;
        setAssignedTasks(drivers[select_driver], bestN, bestSol);
        unassigned_first_tasks[select_task_uaidx] = NULL_ID;
    }
    formatSchedule(drivers.size());
    return E_NORMAL;
}
int ALG::preProcess()
    //check input parameters and set internal vars
{
    printf("read %lu drivers, %lu tasks, %d locations:\n", drivers.size(), tasks.size(), nLocations);
    if (drivers.size() > MAXNDRIVERS) return E_EXCEED_MAXN;
    if (tasks.size() > MAXNTASKS) return E_EXCEED_MAXN;
    if (nLocations > MAXNLOCATIONS) return E_EXCEED_MAXN;
    auto maxdist = map[0][0];
    for (int i=0; i<nLocations; i++) {
        map[i][i] = 0;
        for (int j=0; j<nLocations; j++) if (maxdist < map[i][j]) maxdist = map[i][j];
    }
    for (int i=0; i<nLocations; i++) {
        for (int j=0; j<nLocations; j++) if (map[i][j] < -0.001) map[i][j] = maxdist;
    }
    // set drivers, the tasklist maybe updated later according to curtask
    for (unsigned int i=0; i<drivers.size(); i++) {
        if (!IN_RANGE(drivers[i].location, nLocations)) return E_WRONG_INDEX;
        if (drivers[i].did != (int)i) return E_WRONG_INDEX;
        printf("read driver %u : %d\n", i, drivers[i].maxNOrder);
        if (drivers[i].maxNOrder > MAXNORDER) return E_EXCEED_MAXN;
        drivers[i].designatedTasks.clear();
    }
    for (unsigned int i=0; i<tasks.size(); i++) {
        if (!IN_RANGE(tasks[i].location, nLocations)) return E_WRONG_INDEX;
        if (!IN_RANGE_OR_NULL(tasks[i].did, (int)drivers.size())) return E_WRONG_INDEX;
        if (!IN_RANGE_OR_NULL(tasks[i].nextTask, (int)tasks.size())) return E_WRONG_INDEX;
        if (!IN_RANGE_OR_NULL(tasks[i].prevTask, (int)tasks.size())) return E_WRONG_INDEX;
        if (tasks[i].tid != (int)i) return E_WRONG_INDEX;
        if (tasks[i].rwdPerSec < 0) return E_CONFLICT_SETTING;
        if (tasks[i].pnlPerSec < 0) return E_CONFLICT_SETTING;
        if (tasks[i].rwdOneTime < 0) return E_CONFLICT_SETTING;
        if (tasks[i].pnlOneTime < 0) return E_CONFLICT_SETTING;
    }
    for (unsigned int i=0; i<tasks.size(); i++) {
        //after checking index range
        if (tasks[i].nextTask != NULL_ID) {
            if (tasks[tasks[i].nextTask].prevTask != (int)i) {
                printf("task %u nextTask error\n", i);
                return E_CONFLICT_SETTING;
            }
        }
        if (tasks[i].prevTask != NULL_ID) {
            if (tasks[tasks[i].prevTask].nextTask != (int)i) {
                printf("task %u prevTask error\n", i);
                return E_CONFLICT_SETTING;
            }
        }
        if (tasks[i].did != NULL_ID) {
            int j = tasks[i].did;
            // set drivers[].designatedTasks
            drivers[j].designatedTasks.push_back(i);
            if (tasks[i].nextTask != NULL_ID) {
                if (tasks[tasks[i].nextTask].did != j) {
                    fprintf(stderr, "delivery task not properly pre-assigned.\n");
                    return E_CONFLICT_SETTING;
                }
            }
        }
    }
    for (unsigned int i=0; i<drivers.size(); i++) {
        drivers[i].reset();
        arrange_future_tasks(drivers[i]);
    }
    return E_NORMAL;
}
bool ALG::tryAssignOrderToDriver(int iTask, CDriver &driver, double &evaDiff, unsigned int &n, int bestSol[MAXNPARALLELTASKS])
{
    arrange_future_tasks(driver);
    if (driver._nOrder >= driver.maxNOrder) return false;
    double bestFTime = -1;
    double bestEva = 0;
    int sol[MAXNPARALLELTASKS];
    int tids[MAXNPARALLELTASKS];
    bool taskDoneMark[MAXNTASKS];
    //vector<bool> mark(n, false);
    n = driver._assignedTasks.size();
    for (unsigned int i=0; i<n; i++) {
        tids[i] = driver._assignedTasks[i]; taskDoneMark[tids[i]] = false;
    }
    while (iTask != NULL_ID) {
        tids[n] = iTask; taskDoneMark[tids[n]] = false;
        n++;
        iTask = tasks[iTask].nextTask;
    }
    _searchwEva(driver, driver._available, driver._loc, 0, n, tids, sol, taskDoneMark, bestFTime, bestSol, bestEva);
    if (bestFTime<0 || bestFTime>driver.offTime) return false;
    evaDiff = -driver._eva + calEva(driver, n, bestSol);
    return true;
}

void ALG::_searchwEva(CDriver &driver, CTime curTime, int curLoc, int nFinished, int n,
        int tids[], int sol[], bool taskDoneMark[], double &bestFTime, int bestSol[], double &bestEva)
{
    if (nFinished == n) {
        double eva = calEva(driver, n, sol);
        if (bestFTime < 0) {
            bestFTime = curTime;
            bestEva = eva;
            for (int i=0; i<nFinished; i++) bestSol[i] = sol[i];
            return;
        }
        if (eva > bestEva) {
            bestEva = eva;
            for (int i=0; i<nFinished; i++) bestSol[i] = sol[i];
        }
        if (bestFTime > curTime) {
            bestFTime = curTime;
        }
        return;
    }
    //prune
    if (bestFTime >= 0 && n>=8 && curTime > bestFTime && nFinished < n/2) return;
    for (int j=nFinished; j<n; j++) {
        int i = tids[j];
        CTime arvTime = curTime + MOVING_TIME(curLoc,tasks[i].location,driver);
        if (tasks[i].prevTask != NULL_ID && !taskDoneMark[tasks[i].prevTask])
            continue; //its depend task hasn't finished
        CTime nextTime = calNextAvailable(arvTime, tasks[i], driver);
        sol[nFinished] = i;
        tids[j] = tids[nFinished];
        taskDoneMark[i] = true;
        _searchwEva(driver, nextTime, tasks[i].location, nFinished+1, n, tids, sol, taskDoneMark, bestFTime, bestSol, bestEva);
        taskDoneMark[i] = false;
        tids[j] = i;
    }
}
void ALG::_searchOptTime(CDriver &driver, CTime curTime, int curLoc, int nFinished, int n,
        int tids[], int sol[], bool taskDoneMark[], double &bestFTime, int bestSol[])
{
    if (nFinished == n) {
        if (bestFTime < 0 || curTime < bestFTime) {
            bestFTime = curTime;
            for (int i=0; i<nFinished; i++)
                bestSol[i] = sol[i];
        }
    }
    if (bestFTime >= 0 && curTime > bestFTime)
        return;
    for (int j=nFinished; j<n; j++) {
        int i = tids[j];
        CTime arvTime = curTime + MOVING_TIME(curLoc,tasks[i].location,driver);
        if (tasks[i].prevTask != NULL_ID && !taskDoneMark[tasks[i].prevTask])
            continue; //its depend task hasn't finished
        CTime nextTime = calNextAvailable(arvTime, tasks[i], driver);
        sol[nFinished] = i;
        tids[j] = tids[nFinished];
        taskDoneMark[i] = true;
        _searchOptTime(driver, nextTime, tasks[i].location, nFinished+1, n, tids, sol, taskDoneMark, bestFTime, bestSol);
        taskDoneMark[i] = false;
        tids[j] = i;
    }
}

int ALG::findScheduleSingleDriverOptimal()
{
    int ret = preProcess(); 
    if (ret != E_NORMAL)
        return ret;
    unsigned int n = tasks.size();
    if (n>MAXNPARALLELTASKS) return E_EXCEED_MAXN;
    drivers[0]._assignedTasks.clear();
    for (unsigned int i=0; i<n; i++) {
        drivers[0]._assignedTasks.push_back(i);
    }
    arrange_future_tasks(drivers[0]);
    formatSchedule(1);
    return E_NORMAL;
}

void resetPermutation(int * a, int n)
{
    for (int i=0; i<n; i++) a[i] = i;
}
bool nextPermutation(int * a, int n)
{
    int i=n-2;
    while (i>=0 && a[i]>a[i+1]) i--;
    if (i<0) return false;
    for (int j=i+1; j<n; j++) if (j+1>=n || a[j+1]<a[i]) {
        int temp = a[j];
        a[j] = a[i];
        a[i] = temp;
        break;
    }
    i++; 
    int j=n-1;
    while (i<j) {
        int temp = a[i];
        a[i] = a[j];
        a[j] = temp;
        i++; j--;
    }
    return true;
}
