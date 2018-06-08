#ifndef PARAS_H
#define PARAS_H

#include <string>
#include <vector>

const char* version = "0.1.1";
using std::string;
using std::vector;
#define MAXNORDER 5
#define MAXNPARALLELTASKS 10
#define MAXNLOCATIONS 100
#define MAXNTASKS 100
#define MAXNDRIVERS 200

#define E_NORMAL  0
#define E_EXCEED_MAXN 1
//#define E_UNKNOWN_LOC_DRIVER 2
//#define E_UNKNOWN_LOC_TASK 3
//#define E_UNKNOWN_DRIVER_TASK 4
//#define E_UNKNOWN_DEPEND_TASK 5
//#define E_DUPLICATE_CURTASK 6
#define E_WRONG_INDEX 7
#define E_CONFLICT_SETTING 8
/*
   class CTime
   {
   public: 
   CTime(int minuteToNow):_tMin(minuteToNow){}
   private:
   int _tMin;
   };
   */

typedef double CTime;
typedef double CRTime;
typedef int CID; //must be integer, as array index
#define NULL_ID   -1

typedef CID CLocationID;
class ALG;

class CDriver
{
public:
    CDriver(int driverID=NULL_ID,
            CTime availableTime=0,
            CTime offTime=0,
            int location=NULL_ID,
            int maxNOrder=0,
            double distFactor=1,
            double pickFactor=1,
            double deliFactor=1
            )
        :did(driverID),
        availableTime(availableTime),
        offTime(offTime),
        location(location),
        maxNOrder(maxNOrder),
        distFactor(distFactor),
        pickFactor(pickFactor),
        deliFactor(deliFactor)
    {
        designatedTasks.clear();
    }
    const int 	    did;
    const CTime     availableTime;
    const CTime	    offTime;
    const int	    location;
    const int       maxNOrder;
    const double    distFactor;
    const double    pickFactor;
    const double    deliFactor;

    void reset() {
        _nOrder = -1; //unknown, any read to _eva should also respect this
        _loc = location;
        _available = availableTime;
        _assignedTasks = designatedTasks;
    }

protected:
    vector<int>	designatedTasks; //tasks must been done by this driver
    vector<int>	_assignedTasks; //tasks that have been done by this driver in the FUTURE
    CTime	_available;
    int	        _loc;
    int	        _nOrder;//of _assignedTasks, must set to -1 whenever modified _assignedTasks
    double      _eva;//of _assignedTasks
    friend class ALG;
};

class CTask
{
public:
    CTask(int tid=NULL_ID,
            int location=NULL_ID,
            CTime deadline=0,
            CTime readyTime=0,
            CRTime execTime=0,
            int did=NULL_ID,
            int prevTask=NULL_ID,
            int nextTask=NULL_ID,
            double rwdOneTime=0,
            double pnlOneTime=0,
            double rwdPerSec=1,
            double pnlPerSec=1
         )
        :tid(tid),
        location(location),
        deadline(deadline),
        readyTime(readyTime),
        execTime(execTime),
        did(did),
        prevTask(prevTask),
        nextTask(nextTask),
        rwdOneTime(rwdOneTime),
        pnlOneTime(pnlOneTime),
        rwdPerSec(rwdPerSec),
        pnlPerSec(pnlPerSec)
    {
    };
    const int 	    tid;
    const int	    location;
    const CTime	    deadline; 
    const CTime	    readyTime;
    const CRTime    execTime;
    const int 	    did; //if != NULL_DRIVERID, this task can only be assigned to this driver
    const int 	    prevTask;// this task can only start after prevTaskID, and be carried out by the same driver
    const int 	    nextTask;
    const double    rwdOneTime, pnlOneTime;
    const double    rwdPerSec, pnlPerSec;
protected:
    friend class ALG;
};

class CScheduleItem
{ 
public:
    CID did;
    double eva;
    vector<CID>  tids; //the tasks assigned to this driver, in chronological order
    vector<CTime>  completeTime;
};

//parameter: drivers[in], tasks[in], paths[in], schedule[out]. returns the error code
//the private member of input classes may be altered. 
class ALG{
public:
    static int findScheduleGreedy();
    static int findScheduleSingleDriverOptimal();

    static vector<CDriver> drivers;
    static vector<CTask> tasks;
    static int nLocations;
    static double map[MAXNLOCATIONS][MAXNLOCATIONS];
    static vector<CScheduleItem> schedule;

private:
    static int preProcess();
    static int formatSchedule(unsigned int nDriver);
    static bool tryAssignOrderToDriver(int iTask, CDriver &driver, double &evaDiff, unsigned int &n, int bestSol[MAXNPARALLELTASKS]);
    static void setAssignedTasks(CDriver &driver, unsigned int n, int bestSol[MAXNPARALLELTASKS]);
    static bool arrange_future_tasks(CDriver & driver);
    //static void assignTaskToDriver(int iTask, int iDriver);
    //static double tryAddTask(int iTask, CDriver & driver);
    //return how good to choose (the new task) iTask as the next task, 
    //return 0 if infeasible or the next job at hand is better chosen as the next task
    //static bool select_next_task(CDriver & driver, int &iSelectedTask);
    static void calFTime(CDriver &driver, CTime fTimes[]);
    static double calEva(CDriver &driver, unsigned int n, int tids[]);
    static void _searchOptTime(CDriver &driver, CTime curTime, int curLoc, int nFinished, int n,
        int tids[], int sol[], bool taskDoneMark[], double &bestFTime, int bestSol[]);
    static void _searchwEva(CDriver &driver, CTime curTime, int curLoc, int nFinished, int n,
        int tids[], int sol[], bool taskDoneMark[], double &bestFTime, int bestSol[], double &bestEva);

};
#endif
