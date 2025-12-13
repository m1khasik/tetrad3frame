program LegacyCSV;

{$mode objfpc}{$H+}

uses
  SysUtils, DateUtils, Process, BaseUnix;

function GetEnvDef(const name, def: string): string;
var 
  v: string;
begin
  v := GetEnvironmentVariable(name);
  if v = '' then 
    Exit(def) 
  else 
    Exit(v);
end;

function RandFloat(minV, maxV: Double): Double;
begin
  Result := minV + Random * (maxV - minV);
end;

procedure GenerateAndCopy();
var
  outDir, fn, fullpath, pghost, pgport, pguser, pgpass, pgdb: string;
  f: TextFile;
  ts: string;
  proc: TProcess;
  i: Integer;
  timestamp: TDateTime;
  voltage, temp: Double;
  isActive: Boolean;
  statusText: string;
begin
  outDir := GetEnvDef('CSV_OUT_DIR', '/data/csv');
  ts := FormatDateTime('yyyymmdd_hhnnss', Now);
  fn := 'telemetry_' + ts + '.csv';
  fullpath := IncludeTrailingPathDelimiter(outDir) + fn;

  // Generate data with proper types
  timestamp := Now;
  voltage := RandFloat(3.2, 12.6);
  temp := RandFloat(-50.0, 80.0);
  isActive := Random(2) = 1; // Random boolean
  statusText := 'Status_' + IntToStr(Random(1000));

  // write CSV with proper types: timestamp, boolean, numbers, strings
  AssignFile(f, fullpath);
  try
    Rewrite(f);
    // Header with types
    Writeln(f, 'recorded_at,voltage,temp,is_active,status_text,source_file');
    // Data row with proper formatting
    Writeln(f, 
      FormatDateTime('yyyy-mm-dd hh:nn:ss', timestamp) + ',' +
      FormatFloat('0.00', voltage) + ',' +
      FormatFloat('0.00', temp) + ',' +
      BoolToStr(isActive, 'TRUE', 'FALSE') + ',' +
      '"' + statusText + '",' +
      '"' + fn + '"'
    );
  finally
    CloseFile(f);
  end;

  // COPY into Postgres
  pghost := GetEnvDef('PGHOST', 'db');
  pgport := GetEnvDef('PGPORT', '5432');
  pguser := GetEnvDef('PGUSER', 'monouser');
  pgpass := GetEnvDef('PGPASSWORD', 'monopass');
  pgdb   := GetEnvDef('PGDATABASE', 'monolith');

  // Use TProcess to run psql with environment variables
  proc := TProcess.Create(nil);
  try
    proc.Executable := 'psql';
    
    // Build connection string as a single parameter
    proc.Parameters.Add('-h');
    proc.Parameters.Add(pghost);
    proc.Parameters.Add('-p');
    proc.Parameters.Add(pgport);
    proc.Parameters.Add('-U');
    proc.Parameters.Add(pguser);
    proc.Parameters.Add('-d');
    proc.Parameters.Add(pgdb);
    proc.Parameters.Add('-c');
    proc.Parameters.Add('\copy telemetry_legacy(recorded_at, voltage, temp, source_file) FROM ''' + fullpath + ''' WITH (FORMAT csv, HEADER true)');
    
    // Also generate XLSX file using Python script
    // Note: XLSX generation is handled by the run.sh script monitoring CSV files
    
    // Set PGPASSWORD environment variable
    proc.Environment.Add('PGPASSWORD=' + pgpass);
    
    // Preserve existing environment variables
    for i := 1 to GetEnvironmentVariableCount do
      proc.Environment.Add(GetEnvironmentString(i));
    
    proc.Options := [poWaitOnExit, poUsePipes, poStderrToOutPut];
    proc.ShowWindow := swoHide;
    
    WriteLn('Executing: psql -h ', pghost, ' -p ', pgport, ' -U ', pguser, ' -d ', pgdb);
    
    proc.Execute;
    
    if proc.ExitStatus <> 0 then
    begin
      // Read error output
      raise Exception.Create('psql failed with exit code: ' + IntToStr(proc.ExitStatus));
    end
    else
    begin
      WriteLn('Successfully copied: ', fn);
    end;
      
  finally
    proc.Free;
  end;
end;

var 
  period: Integer;
begin
  Randomize;
  period := StrToIntDef(GetEnvDef('GEN_PERIOD_SEC', '300'), 300);
  WriteLn('Starting LegacyCSV generator. Period: ', period, ' seconds');
  WriteLn('Output directory: ', GetEnvDef('CSV_OUT_DIR', '/data/csv'));
  
  while True do
  begin
    try
      GenerateAndCopy();
    except
      on E: Exception do
        WriteLn('Legacy error: ', E.Message);
    end;
    Sleep(period * 1000);
  end;
end.